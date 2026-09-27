<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Data\CheckoutClientConfig;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Data\WebhookSource;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Gateways\Exceptions\GatewayOperationNotImplementedException;
use App\Modules\Gateways\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use Stripe\Account;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Stripe\Webhook;

/**
 * Stripe adapter of the PaymentGateway port (plan 12, ADR-019). Every call
 * goes through StripeClientFactory / StripeCallContext; no other class in
 * the application talks to the Stripe API for the payments domain.
 */
final readonly class StripeGateway implements PaymentGateway
{
    /** Stripe's PaymentIntent `description` is kept short (ours is at most 500 characters). */
    private const int DESCRIPTION_MAX = 500;

    /** Card brand, country and last four; authorization expiry (capture_before). */
    private const array EXPAND = ['payment_method', 'latest_charge'];

    public function __construct(
        private StripeClientFactory $clients,
        private PlatformStripeKeys $platformKeys,
        private GatewayCredentialsEncrypter $encrypter,
    ) {}

    public function provider(): GatewayProvider
    {
        return GatewayProvider::Stripe;
    }

    /**
     * GET /v1/account in the connection's context: with `Stripe-Account` for
     * Connect methods, with the merchant key for api_key.
     */
    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData
    {
        $context = $this->clients->for($connection);

        try {
            $account = $context->client->accounts->retrieve(null, null, $context->options());
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'retrieveAccount');
        }

        return StripeAccountMapper::toData($account);
    }

    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig
    {
        $context = $this->clients->for($connection);

        return new CheckoutClientConfig(
            publishableKey: $context->publishableKey ?? throw new GatewayConfigurationException('The connection has no publishable key.'),
            accountId: $context->stripeAccount,
        );
    }

    /**
     * GET /v1/confirmation_tokens/{id} in the connection's context: the card
     * the payer entered, before anything is charged (plan 11.4, ADR-005).
     */
    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview
    {
        $context = $this->clients->for($connection);

        try {
            $token = $context->client->confirmationTokens->retrieve($confirmationToken, null, $context->options());
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'inspectPaymentMethod');
        }

        $preview = $token->payment_method_preview ?? null;
        $card = $preview instanceof StripeObject ? ($preview->card ?? null) : null;

        return new PaymentMethodPreview(
            country: $card instanceof StripeObject && is_string($card->country ?? null) ? $card->country : null,
            brand: $card instanceof StripeObject && is_string($card->brand ?? null) ? $card->brand : null,
            last4: $card instanceof StripeObject && is_string($card->last4 ?? null) ? $card->last4 : null,
        );
    }

    /**
     * POST /v1/payment_intents (plan 12.4): a direct charge on the tenant's
     * account (`Stripe-Account` for Connect methods, the merchant key for
     * api_key), card only (ADR-018), manual capture (ADR-0050), our
     * identifiers only in the metadata, no application fee (ADR-011). With a
     * `providerPaymentId`, updates amount and currency (allowed before
     * confirmation).
     */
    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment
    {
        $context = $this->clients->for($connection);
        $params = [
            'amount' => $request->amountMinor,
            'currency' => strtolower($request->currency),
            'description' => mb_substr($request->description, 0, self::DESCRIPTION_MAX),
            'metadata' => $request->metadata,
        ];

        try {
            $intent = $request->providerPaymentId === null
                ? $context->client->paymentIntents->create([
                    ...$params,
                    'capture_method' => 'manual',
                    'payment_method_types' => ['card'],
                    'expand' => self::EXPAND,
                ], $context->options($request->idempotencyKey))
                : $context->client->paymentIntents->update($request->providerPaymentId, [...$params, 'expand' => self::EXPAND], $context->options($request->idempotencyKey));
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'createOrUpdatePayment');
        }

        return StripePaymentMapper::toProviderPayment($intent);
    }

    /**
     * POST /v1/payment_intents/{id}/confirm with the ConfirmationToken
     * (deferred intent, confirmed on the server). `use_stripe_sdk` lets
     * Stripe.js `handleNextAction` run 3D Secure in the page. A declined card
     * answers 402: the payment is re-read and returned with its failure.
     */
    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey, string $returnUrl, ?string $receiptEmail = null): ProviderPayment
    {
        $context = $this->clients->for($connection);

        try {
            $intent = $context->client->paymentIntents->confirm($providerPaymentId, array_filter([
                'confirmation_token' => $confirmationToken,
                'return_url' => $returnUrl,
                'use_stripe_sdk' => true,
                'receipt_email' => $receiptEmail,
                'expand' => self::EXPAND,
            ], static fn (mixed $value): bool => $value !== null), $context->options($idempotencyKey));
        } catch (CardException) {
            return $this->retrievePayment($connection, $providerPaymentId);
        } catch (InvalidRequestException $e) {
            // Already confirmed (a retry after a lost answer): the payment as it is now.
            if ($e->getStripeCode() === 'payment_intent_unexpected_state') {
                return $this->retrievePayment($connection, $providerPaymentId);
            }

            throw StripeErrorMapper::map($e, 'confirmPayment');
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'confirmPayment');
        }

        return StripePaymentMapper::toProviderPayment($intent);
    }

    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment
    {
        $context = $this->clients->for($connection);

        try {
            $intent = $context->client->paymentIntents->retrieve($providerPaymentId, ['expand' => self::EXPAND], $context->options());
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'retrievePayment');
        }

        return StripePaymentMapper::toProviderPayment($intent);
    }

    /** POST /v1/payment_intents/{id}/capture: the full authorized amount. */
    public function capturePayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        $context = $this->clients->for($connection);

        try {
            $intent = $context->client->paymentIntents->capture($providerPaymentId, ['expand' => self::EXPAND], $context->options($idempotencyKey));
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'capturePayment');
        }

        return StripePaymentMapper::toProviderPayment($intent);
    }

    /**
     * POST /v1/payment_intents/{id}/cancel: voids an uncaptured authorization
     * or closes an unconfirmed payment. A payment that already reached a
     * final state cannot be canceled: it is re-read and returned as it is.
     */
    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        $context = $this->clients->for($connection);

        try {
            $intent = $context->client->paymentIntents->cancel($providerPaymentId, ['expand' => self::EXPAND], $context->options($idempotencyKey));
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'payment_intent_unexpected_state') {
                return $this->retrievePayment($connection, $providerPaymentId);
            }

            throw StripeErrorMapper::map($e, 'cancelPayment');
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'cancelPayment');
        }

        return StripePaymentMapper::toProviderPayment($intent);
    }

    public function refund(GatewayConnection $connection, RefundRequest $request): ProviderRefund
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 7');
    }

    public function retrieveRefund(GatewayConnection $connection, string $providerRefundId): ProviderRefund
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 7');
    }

    public function reduceWebhookPayload(string $payload): string
    {
        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return '{}';
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $object = is_array($data['object'] ?? null) ? $data['object'] : [];

        return (string) json_encode(array_filter([
            'id' => $event['id'] ?? null,
            'object' => $event['object'] ?? null,
            'type' => $event['type'] ?? null,
            'account' => $event['account'] ?? null,
            'livemode' => $event['livemode'] ?? null,
            'created' => $event['created'] ?? null,
            'api_version' => $event['api_version'] ?? null,
            'data' => ['object' => array_filter([
                'id' => $object['id'] ?? null,
                'object' => $object['object'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)],
            'axispay_reduced' => true,
        ], static fn (mixed $value): bool => $value !== null), JSON_UNESCAPED_SLASHES);
    }

    public function eventKind(string $providerEventType, bool $direct): ProviderEventKind
    {
        return match ($providerEventType) {
            'account.updated' => ProviderEventKind::AccountUpdated,
            'account.application.deauthorized' => $direct ? ProviderEventKind::Unhandled : ProviderEventKind::AccountDeauthorized,
            'payment_intent.amount_capturable_updated',
            'payment_intent.canceled',
            'payment_intent.payment_failed',
            'payment_intent.processing',
            'payment_intent.requires_action',
            'payment_intent.succeeded' => ProviderEventKind::PaymentUpdated,
            default => ProviderEventKind::Unhandled,
        };
    }

    /**
     * Plan 14.2: the signature is checked on the raw body with the secret of
     * the endpoint that received it (Connect endpoint of a mode, or the
     * connection's own endpoint). Stripe's default tolerance (5 minutes).
     */
    public function parseWebhook(string $rawBody, array $headers, WebhookSource $source): ProviderWebhookEvent
    {
        $signature = self::header($headers, 'stripe-signature');

        if ($signature === null) {
            throw new InvalidWebhookSignatureException('The Stripe-Signature header is missing.');
        }

        $tolerance = config('axispay.gateways.stripe.webhook_tolerance');

        try {
            $event = Webhook::constructEvent($rawBody, $signature, $this->signingSecret($source), is_int($tolerance) ? $tolerance : Webhook::DEFAULT_TOLERANCE);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new InvalidWebhookSignatureException('The Stripe webhook signature is invalid.');
        }

        return $this->toProviderEvent($event, $rawBody, $source);
    }

    private function signingSecret(WebhookSource $source): string
    {
        if ($source->connection === null) {
            return $this->platformKeys->connectWebhookSecret($source->livemode);
        }

        $secret = $source->connection->provider_webhook_secret
            ?? throw new InvalidWebhookSignatureException('The connection has no webhook secret.');

        return $this->encrypter->decrypt($secret, $source->connection->credentials_key_version);
    }

    private function toProviderEvent(Event $event, string $rawBody, WebhookSource $source): ProviderWebhookEvent
    {
        $object = $event->data->object ?? null;
        $objectId = $object instanceof StripeObject && is_string($object->id ?? null) ? $object->id : null;
        $eventAccount = is_string($event->account ?? null) && $event->account !== '' ? $event->account : null;
        $accountId = $eventAccount;

        if ($source->connection !== null) {
            // Direct endpoint (api_key): the event belongs to the connection's
            // own account. A foreign account means a misrouted or forged event.
            $accountId = $source->connection->provider_account_id;
            $describesAccount = $object instanceof Account;

            if (($eventAccount !== null && $eventAccount !== $accountId) || ($describesAccount && $objectId !== $accountId)) {
                throw new InvalidWebhookSignatureException('The event does not belong to the connection account.');
            }
        }

        return new ProviderWebhookEvent(
            providerEventId: $event->id,
            type: $event->type,
            kind: $this->eventKind($event->type, $source->isDirect()),
            providerAccountId: $accountId,
            livemode: $event->livemode === true,
            objectId: $objectId,
            rawPayload: $rawBody,
            reducedPayload: $this->reduceWebhookPayload($rawBody),
            attemptReference: $object instanceof PaymentIntent ? StripePaymentMapper::attemptReference($object->metadata ?? null) : null,
        );
    }

    /**
     * @param  array<string, list<string|null>|string|null>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) !== $name) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
