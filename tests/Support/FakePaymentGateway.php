<?php

declare(strict_types=1);

namespace Tests\Support;

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
use App\Modules\Gateways\Exceptions\GatewayOperationNotImplementedException;
use App\Modules\Gateways\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayFactory;
use LogicException;
use Throwable;

/**
 * Deterministic PaymentGateway for domain tests (plan 12.1): accounts are
 * configured per gateway account ID, failures are queued exceptions, and
 * every call is recorded. No network, no Stripe SDK. Webhooks are "signed"
 * with a shared test secret header.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public const string SIGNATURE = 'fake-signature';

    /** @var array<string, ConnectedAccountData> */
    private array $accounts = [];

    /** @var array<string, Throwable> */
    private array $failures = [];

    /** @var list<string> */
    public array $calls = [];

    public static function install(): self
    {
        $fake = new self;
        app(GatewayFactory::class)->fake($fake);

        return $fake;
    }

    /**
     * @param  list<string>  $currentlyDue
     */
    public function withAccount(string $accountId, bool $chargesEnabled = true, string $country = 'MX', array $currentlyDue = []): self
    {
        $requirements = ConnectedAccountData::emptyRequirements();
        $requirements['currently_due'] = $currentlyDue;
        $requirements['disabled_reason'] = $chargesEnabled ? null : 'requirements.past_due';

        $this->accounts[$accountId] = new ConnectedAccountData($accountId, $country, 'mxn', $chargesEnabled, $chargesEnabled, $chargesEnabled, $requirements);
        unset($this->failures[$accountId]);

        return $this;
    }

    public function failingWith(string $accountId, Throwable $exception): self
    {
        $this->failures[$accountId] = $exception;

        return $this;
    }

    public function provider(): GatewayProvider
    {
        return GatewayProvider::Stripe;
    }

    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData
    {
        $id = (string) $connection->provider_account_id;
        $this->calls[] = 'retrieveAccount:'.$id;

        if (isset($this->failures[$id])) {
            throw $this->failures[$id];
        }

        return $this->accounts[$id] ?? throw new LogicException("FakePaymentGateway has no account {$id}.");
    }

    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig
    {
        return $connection->isApiKey()
            ? new CheckoutClientConfig((string) $connection->credentials_publishable, null)
            : new CheckoutClientConfig('pk_test_fake_platform', $connection->provider_account_id);
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

    public function eventKind(string $providerEventType, bool $direct): ProviderEventKind
    {
        return match ($providerEventType) {
            'account.updated' => ProviderEventKind::AccountUpdated,
            'account.application.deauthorized' => $direct ? ProviderEventKind::Unhandled : ProviderEventKind::AccountDeauthorized,
            default => ProviderEventKind::Unhandled,
        };
    }

    public function reduceWebhookPayload(string $payload): string
    {
        $event = json_decode($payload, true);

        return (string) json_encode(is_array($event) ? ['id' => $event['id'] ?? null, 'type' => $event['type'] ?? null, 'axispay_reduced' => true] : []);
    }

    public function parseWebhook(string $rawBody, array $headers, WebhookSource $source): ProviderWebhookEvent
    {
        $signature = $headers['stripe-signature'] ?? null;
        $signature = is_array($signature) ? ($signature[0] ?? null) : $signature;

        if ($signature !== self::SIGNATURE) {
            throw new InvalidWebhookSignatureException('Fake signature mismatch.');
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new InvalidWebhookSignatureException('Malformed fake event.');
        }

        $account = $source->connection !== null
            ? $source->connection->provider_account_id
            : (is_string($event['account'] ?? null) ? $event['account'] : null);

        return new ProviderWebhookEvent($event['id'], $event['type'], $this->eventKind($event['type'], $source->isDirect()), $account, ($event['livemode'] ?? false) === true, null, $rawBody, $this->reduceWebhookPayload($rawBody));
    }
}
