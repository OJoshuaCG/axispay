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
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeObject;
use Stripe\Webhook;

/**
 * Stripe adapter of the PaymentGateway port (plan 12, ADR-019). Every call
 * goes through StripeClientFactory / StripeCallContext; no other class in
 * the application talks to the Stripe API for the payments domain.
 */
final readonly class StripeGateway implements PaymentGateway
{
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

    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 4');
    }

    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 4');
    }

    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey): ProviderPayment
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 4');
    }

    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 4');
    }

    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 4');
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
