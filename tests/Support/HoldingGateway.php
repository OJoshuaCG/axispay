<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Data\CheckoutClientConfig;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\ProviderDispute;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Data\WebhookSource;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Models\GatewayConnection;

/**
 * Concurrency tests (RaceHarness): the gateway of a race process that holds
 * inside its confirmation call until the test lets it go. It writes
 * `holding-<pid>` in `$dir` when it arrives, then waits for `release` (at
 * most 30 s), so the test knows the payment is under way and every other
 * process is guaranteed to overlap it. Everything else is the wrapped
 * gateway.
 */
final readonly class HoldingGateway implements PaymentGateway
{
    public function __construct(private PaymentGateway $inner, private string $dir) {}

    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey, string $returnUrl, ?string $receiptEmail = null): ProviderPayment
    {
        file_put_contents($this->dir.'/holding-'.getmypid(), '1');
        $deadline = microtime(true) + 30;

        while (! is_file($this->dir.'/release') && microtime(true) < $deadline) {
            usleep(5_000);
        }

        return $this->inner->confirmPayment($connection, $providerPaymentId, $confirmationToken, $idempotencyKey, $returnUrl, $receiptEmail);
    }

    public function provider(): GatewayProvider
    {
        return $this->inner->provider();
    }

    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData
    {
        return $this->inner->retrieveAccount($connection);
    }

    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig
    {
        return $this->inner->clientConfig($connection);
    }

    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview
    {
        return $this->inner->inspectPaymentMethod($connection, $confirmationToken);
    }

    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment
    {
        return $this->inner->createOrUpdatePayment($connection, $request);
    }

    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment
    {
        return $this->inner->retrievePayment($connection, $providerPaymentId);
    }

    public function capturePayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        return $this->inner->capturePayment($connection, $providerPaymentId, $idempotencyKey);
    }

    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment
    {
        return $this->inner->cancelPayment($connection, $providerPaymentId, $idempotencyKey);
    }

    public function refund(GatewayConnection $connection, RefundRequest $request): ProviderRefund
    {
        return $this->inner->refund($connection, $request);
    }

    public function retrieveRefund(GatewayConnection $connection, string $providerRefundId): ProviderRefund
    {
        return $this->inner->retrieveRefund($connection, $providerRefundId);
    }

    public function listRefunds(GatewayConnection $connection, string $providerPaymentId): array
    {
        return $this->inner->listRefunds($connection, $providerPaymentId);
    }

    public function retrieveDispute(GatewayConnection $connection, string $providerDisputeId): ProviderDispute
    {
        return $this->inner->retrieveDispute($connection, $providerDisputeId);
    }

    public function eventKind(string $providerEventType, bool $direct): ProviderEventKind
    {
        return $this->inner->eventKind($providerEventType, $direct);
    }

    public function reduceWebhookPayload(string $payload): string
    {
        return $this->inner->reduceWebhookPayload($payload);
    }

    public function parseWebhook(string $rawBody, array $headers, WebhookSource $source): ProviderWebhookEvent
    {
        return $this->inner->parseWebhook($rawBody, $headers, $source);
    }
}
