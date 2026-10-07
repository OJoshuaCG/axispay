<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Enums\ProviderRefundStatus;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Sandbox\SandboxPaymentGateway;
use App\Modules\Tenancy\Models\Tenant;
use Tests\Support\GatewayTestHelpers;

/**
 * The checkout sandbox (ADR-0051) refunds like the real gateway does (CRX-13):
 * only a captured payment, never above what is left, the same key answers the
 * same refund, and what it refunded can be listed back.
 */
function sandboxConnection(): GatewayConnection
{
    return GatewayTestHelpers::connection(Tenant::factory()->create(), false);
}

/** A captured sandbox payment of USD 100.00 (10,000 minor units). */
function sandboxCapturedPayment(SandboxPaymentGateway $sandbox, GatewayConnection $connection): string
{
    $payment = $sandbox->createOrUpdatePayment($connection, new PaymentRequest(10_000, 'USD', 'Order', ['axispay_attempt_id' => 'att'], 'sandbox-create-'.bin2hex(random_bytes(3))));
    $sandbox->confirmPayment($connection, $payment->providerPaymentId, 'ctoken_sandbox_success_abc', 'sandbox-confirm-'.bin2hex(random_bytes(3)), 'https://pay.localhost/x');
    $sandbox->capturePayment($connection, $payment->providerPaymentId, 'sandbox-capture-'.bin2hex(random_bytes(3)));

    return $payment->providerPaymentId;
}

it('refunds a captured payment in part, lists the refunds and refuses more than is left', function (): void {
    $sandbox = app(SandboxPaymentGateway::class);
    $connection = sandboxConnection();
    $paymentId = sandboxCapturedPayment($sandbox, $connection);

    $first = $sandbox->refund($connection, new RefundRequest($paymentId, 4_000, 'k1', 'duplicate', '01K6REFUND00000000000000A1'));
    $second = $sandbox->refund($connection, new RefundRequest($paymentId, 6_000, 'k2', null, '01K6REFUND00000000000000A2'));

    expect($first->status)->toBe(ProviderRefundStatus::Succeeded)
        ->and($first->providerPaymentId)->toBe($paymentId)
        ->and($first->currency)->toBe('USD')
        ->and($first->reference)->toBe('01K6REFUND00000000000000A1')
        ->and($sandbox->retrieveRefund($connection, $first->providerRefundId)->amountMinor)->toBe(4_000)
        ->and(array_map(static fn ($refund): int => $refund->amountMinor, $sandbox->listRefunds($connection, $paymentId)))->toBe([4_000, 6_000])
        ->and($second->providerRefundId)->not->toBe($first->providerRefundId);

    $tooMuch = thrownBy(GatewayRequestException::class, static fn () => $sandbox->refund($connection, new RefundRequest($paymentId, 1, 'k3', null, 'r3')));

    expect($tooMuch->providerCode)->toBe('amount_too_large');
});

it('answers the same refund for the same idempotency key', function (): void {
    $sandbox = app(SandboxPaymentGateway::class);
    $connection = sandboxConnection();
    $paymentId = sandboxCapturedPayment($sandbox, $connection);

    $one = $sandbox->refund($connection, new RefundRequest($paymentId, 1_000, 'same-key', null, 'r1'));
    $two = $sandbox->refund($connection, new RefundRequest($paymentId, 1_000, 'same-key', null, 'r1'));

    expect($two->providerRefundId)->toBe($one->providerRefundId)
        ->and($sandbox->listRefunds($connection, $paymentId))->toHaveCount(1);
});

it('refuses to refund a payment that was not captured, and has no disputes', function (): void {
    $sandbox = app(SandboxPaymentGateway::class);
    $connection = sandboxConnection();
    $payment = $sandbox->createOrUpdatePayment($connection, new PaymentRequest(10_000, 'USD', 'Order', [], 'sandbox-create-open'));

    $notCaptured = thrownBy(GatewayRequestException::class, static fn () => $sandbox->refund($connection, new RefundRequest($payment->providerPaymentId, 100, 'k', null, 'r')));
    $noDispute = thrownBy(GatewayRequestException::class, static fn () => $sandbox->retrieveDispute($connection, 'dp_none'));

    expect($notCaptured->providerCode)->toBe('charge_not_refundable')
        ->and($noDispute->providerCode)->toBe('resource_missing');
});

it('refuses live-mode refunds', function (): void {
    $sandbox = app(SandboxPaymentGateway::class);
    $live = GatewayTestHelpers::connection(Tenant::factory()->create(), true);

    $refused = thrownBy(GatewayRequestException::class, static fn () => $sandbox->refund($live, new RefundRequest('pi_x', 100, 'k', null, 'r')));

    expect($refused->providerCode)->toBe('sandbox_live_mode');
});
