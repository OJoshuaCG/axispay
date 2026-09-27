<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Sandbox;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Data\CheckoutClientConfig;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Data\WebhookSource;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayOperationNotImplementedException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\Shared\Ids\SecureToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Server half of the checkout sandbox (ADR-0051; local and testing only, see
 * SandboxMode). Payments live in the cache and follow the scenario encoded
 * in the confirmation token that the browser stub creates:
 *
 *   ctoken_sandbox_<scenario>_<random>
 *
 * | Scenario     | After confirm                                   |
 * |--------------|-------------------------------------------------|
 * | success      | authorized (requires_capture)                   |
 * | decline      | declined (generic_decline)                      |
 * | funds        | declined (insufficient_funds)                   |
 * | threeds      | requires_action; the stub's bank dialog decides |
 * | processing   | processing, authorized a few seconds later      |
 *
 * Capturing succeeds, canceling voids. Idempotency keys are honored like
 * Stripe does (same key, same answer). Account and webhook methods are
 * delegated to the real adapter (webhooks are not simulated: the sandbox
 * relies on the checkout's own synchronous sync).
 */
final class SandboxPaymentGateway implements PaymentGateway
{
    /** Seconds a `processing` sandbox payment takes to be authorized. */
    public const int PROCESSING_SECONDS = 4;

    private const string PREFIX = 'axispay:sandbox:';

    private const array CARDS = [
        'success' => ['visa', '4242'],
        'decline' => ['visa', '0002'],
        'funds' => ['mastercard', '9995'],
        'threeds' => ['visa', '3155'],
        'processing' => ['visa', '1111'],
    ];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly Repository $cache,
    ) {}

    public function provider(): GatewayProvider
    {
        return GatewayProvider::Stripe;
    }

    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData
    {
        return new ConnectedAccountData((string) $connection->provider_account_id, $connection->country ?? 'MX', 'mxn', true, true, true, ConnectedAccountData::emptyRequirements());
    }

    /** Same shape as the real adapter: the merchant's key without an account for api_key. */
    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig
    {
        return $connection->isApiKey()
            ? new CheckoutClientConfig($connection->credentials_publishable ?? 'pk_test_sandbox_merchant', null)
            : new CheckoutClientConfig('pk_test_sandbox_platform', $connection->provider_account_id);
    }

    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview
    {
        [$brand, $last4] = self::CARDS[self::scenario($confirmationToken)];

        return new PaymentMethodPreview('MX', $brand, $last4);
    }

    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment
    {
        return $this->idempotent($request->idempotencyKey, function () use ($request): array {
            $state = $request->providerPaymentId !== null ? $this->load($request->providerPaymentId) : [
                'id' => 'pi_sandbox_'.SecureToken::base62(12),
                'status' => ProviderPaymentStatus::RequiresPaymentMethod->value,
                'metadata' => $request->metadata,
                'card' => null,
                'failure' => null,
                'processing_until' => null,
            ];
            $state['amount'] = $request->amountMinor;
            $state['currency'] = strtoupper($request->currency);

            return $this->store($state);
        });
    }

    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey, string $returnUrl, ?string $receiptEmail = null): ProviderPayment
    {
        return $this->idempotent($idempotencyKey, function () use ($providerPaymentId, $confirmationToken): array {
            $state = $this->load($providerPaymentId);

            if (! in_array($state['status'], [ProviderPaymentStatus::RequiresPaymentMethod->value, ProviderPaymentStatus::RequiresConfirmation->value], true)) {
                throw new GatewayRequestException('The sandbox payment cannot be confirmed in its state.', 'payment_intent_unexpected_state', null, 400);
            }

            $scenario = self::scenario($confirmationToken);
            [$brand, $last4] = self::CARDS[$scenario];
            $state['card'] = ['brand' => $brand, 'last4' => $last4, 'country' => 'MX'];
            $state['failure'] = null;

            $state['status'] = match ($scenario) {
                'success' => ProviderPaymentStatus::RequiresCapture->value,
                'threeds' => ProviderPaymentStatus::RequiresAction->value,
                'processing' => ProviderPaymentStatus::Processing->value,
                default => ProviderPaymentStatus::RequiresPaymentMethod->value, // decline, funds
            };

            if ($scenario === 'decline' || $scenario === 'funds') {
                $state['failure'] = ['reference' => 'ch_sandbox_'.SecureToken::base62(12), 'code' => 'card_declined', 'decline_code' => $scenario === 'funds' ? 'insufficient_funds' : 'generic_decline', 'message' => 'Your card was declined.'];
            }

            if ($scenario === 'processing') {
                $state['processing_until'] = CarbonImmutable::now()->addSeconds(self::PROCESSING_SECONDS)->getTimestamp();
            }

            return $this->store($state);
        });
    }

    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment
    {
        $state = $this->load($providerPaymentId);

        if ($state['status'] === ProviderPaymentStatus::Processing->value && is_int($state['processing_until']) && $state['processing_until'] <= CarbonImmutable::now()->getTimestamp()) {
            $state['status'] = ProviderPaymentStatus::RequiresCapture->value;
            $this->store($state);
        }

        return $this->toPayment($state);
    }

    public function capturePayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        return $this->idempotent($idempotencyKey, function () use ($providerPaymentId): array {
            $state = $this->load($providerPaymentId);

            if ($state['status'] !== ProviderPaymentStatus::RequiresCapture->value) {
                throw new GatewayRequestException('The sandbox payment is not authorized.', 'payment_intent_unexpected_state', null, 400);
            }

            $state['status'] = ProviderPaymentStatus::Succeeded->value;

            return $this->store($state);
        });
    }

    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        return $this->idempotent($idempotencyKey, function () use ($providerPaymentId): array {
            $state = $this->load($providerPaymentId);

            if (! in_array($state['status'], [ProviderPaymentStatus::Succeeded->value, ProviderPaymentStatus::Canceled->value, ProviderPaymentStatus::Processing->value], true)) {
                $state['status'] = ProviderPaymentStatus::Canceled->value;
                $this->store($state);
            }

            return $state;
        });
    }

    /**
     * The browser stub's "bank" answered the 3D Secure challenge: approved
     * authorizes the payment, anything else returns it to the payer with an
     * authentication failure (as Stripe does).
     */
    public function completeNextAction(string $clientSecret, bool $approved): void
    {
        $providerPaymentId = explode('_secret_', $clientSecret)[0];
        $state = $this->load($providerPaymentId);

        if (($state['client_secret'] ?? null) !== $clientSecret || $state['status'] !== ProviderPaymentStatus::RequiresAction->value) {
            return;
        }

        $state['status'] = $approved ? ProviderPaymentStatus::RequiresCapture->value : ProviderPaymentStatus::RequiresPaymentMethod->value;
        $state['failure'] = $approved ? null : ['reference' => 'ch_sandbox_'.SecureToken::base62(12), 'code' => 'payment_intent_authentication_failure', 'decline_code' => null, 'message' => 'The provided payment method failed authentication.'];
        $this->store($state);
    }

    public function refund(GatewayConnection $connection, RefundRequest $request): ProviderRefund
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 7');
    }

    public function retrieveRefund(GatewayConnection $connection, string $providerRefundId): ProviderRefund
    {
        throw GatewayOperationNotImplementedException::for(__FUNCTION__, 'Phase 7');
    }

    public function eventKind(string $providerEventType, bool $direct): ProviderEventKind
    {
        return $this->stripe->eventKind($providerEventType, $direct);
    }

    public function reduceWebhookPayload(string $payload): string
    {
        return $this->stripe->reduceWebhookPayload($payload);
    }

    public function parseWebhook(string $rawBody, array $headers, WebhookSource $source): ProviderWebhookEvent
    {
        return $this->stripe->parseWebhook($rawBody, $headers, $source);
    }

    private static function scenario(string $confirmationToken): string
    {
        if (preg_match('/^ctoken_sandbox_(success|decline|funds|threeds|processing)_[A-Za-z0-9]+$/', $confirmationToken, $match) !== 1) {
            throw new GatewayRequestException('Unknown sandbox confirmation token.', 'resource_missing', null, 404);
        }

        return $match[1];
    }

    /**
     * Same key, same answer (Stripe's idempotency), for 24 hours.
     *
     * @param  callable(): array<string, mixed>  $operation
     */
    private function idempotent(string $key, callable $operation): ProviderPayment
    {
        $cacheKey = self::PREFIX.'idem:'.hash('sha256', $key);
        $replayed = $this->cache->get($cacheKey);

        if (is_string($replayed)) {
            return $this->toPayment($this->load($replayed));
        }

        $state = $operation();
        $this->cache->put($cacheKey, self::str($state['id'] ?? null), 86_400);

        return $this->toPayment($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $id): array
    {
        $state = $this->cache->get(self::PREFIX.'pi:'.$id);

        if (! is_array($state) || ! is_string($state['id'] ?? null)) {
            throw new GatewayRequestException('No such sandbox payment.', 'resource_missing', null, 404);
        }

        $typed = [];

        foreach ($state as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function store(array $state): array
    {
        $id = self::str($state['id'] ?? null) ?? throw new LogicException('A sandbox payment needs an ID.');

        if ($state['status'] === ProviderPaymentStatus::RequiresAction->value) {
            $state['client_secret'] ??= $id.'_secret_'.SecureToken::base62(16);
        }

        $this->cache->put(self::PREFIX.'pi:'.$id, $state, 86_400);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function toPayment(array $state): ProviderPayment
    {
        $status = ProviderPaymentStatus::from(self::str($state['status']) ?? throw new LogicException('Corrupt sandbox payment.'));
        $card = is_array($state['card'] ?? null) ? $state['card'] : null;
        $failure = is_array($state['failure'] ?? null) ? $state['failure'] : null;
        $metadata = is_array($state['metadata'] ?? null) ? $state['metadata'] : [];
        $amount = is_int($state['amount'] ?? null) ? $state['amount'] : 0;

        return new ProviderPayment(
            providerPaymentId: self::str($state['id']) ?? '',
            status: $status,
            amountMinor: $amount,
            currency: self::str($state['currency'] ?? null) ?? 'MXN',
            amountCapturableMinor: $status === ProviderPaymentStatus::RequiresCapture ? $amount : 0,
            clientSecret: $status === ProviderPaymentStatus::RequiresAction ? self::str($state['client_secret'] ?? null) : null,
            cardPreview: $card !== null ? new PaymentMethodPreview(self::str($card['country'] ?? null), self::str($card['brand'] ?? null), self::str($card['last4'] ?? null)) : null,
            failure: $failure !== null && $status === ProviderPaymentStatus::RequiresPaymentMethod
                ? new ProviderPaymentFailure(self::str($failure['reference'] ?? null) ?? 'ch_sandbox', self::str($failure['code'] ?? null), self::str($failure['decline_code'] ?? null), self::str($failure['message'] ?? null))
                : null,
            attemptReference: self::str($metadata['axispay_attempt_id'] ?? null),
            captureBefore: $status === ProviderPaymentStatus::RequiresCapture ? CarbonImmutable::now()->addDays(7)->toIso8601String() : null,
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
