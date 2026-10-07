<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ProviderRefundStatus;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Tests\Support\RefundTestHelpers as Refunds;

use function Pest\Laravel\travel;

/**
 * CRX-14 (plan 12.5, 16.1): the reconciliation is the safety net under the
 * refund events. A refund that stays `pending` holds the money back from the
 * payment, so one whose event never arrived (or whose request was abandoned)
 * is re-read, adopted or, when it was never sent, failed.
 */
it('completes a pending refund whose event never arrived', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->refundsAs(ProviderRefundStatus::Pending);
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '400.00'])->assertCreated();
    $providerRefundId = (string) Refunds::of($link)[0]->provider_refund_id;
    $fake->setRefundStatus($providerRefundId, ProviderRefundStatus::Succeeded);

    // Not stale yet: left alone.
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();
    expect(Refunds::of($link)[0]->status)->toBe(RefundState::Pending)
        ->and($fake->callsTo('retrieveRefund'))->toBe([]);

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect(Refunds::of($link)[0]->status)->toBe(RefundState::Succeeded)
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(40000)
        ->and(Refunds::webhookBodies($link, WebhookEventType::RefundSucceeded))->toHaveCount(1);
});

it('adopts a refund the gateway made whose answer was lost and nobody retried', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->loseNextResponse('refund');
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '400.00'])->assertStatus(502);
    expect(Refunds::of($link)[0]->provider_refund_id)->toBeNull();

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect(Refunds::of($link))->toHaveCount(1)
        ->and(Refunds::of($link)[0]->status)->toBe(RefundState::Succeeded)
        ->and(Refunds::of($link)[0]->provider_refund_id)->not->toBeNull()
        ->and($fake->refundCount((string) $attempt->provider_payment_id))->toBe(1)
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(40000);
});

it('fails a refund that was never sent after a day, and frees the money', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->failNext('refund', new GatewayUnavailableException('Fake: unreachable.'));
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '400.00'])->assertStatus(502);

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();
    expect(Refunds::of($link)[0]->status)->toBe(RefundState::Pending);

    travel(25)->hours();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect(Refunds::of($link)[0]->status)->toBe(RefundState::Failed)
        ->and(Refunds::of($link)[0]->failure_reason)->toBe('refund_not_sent')
        ->and(Refunds::webhookBodies($link, WebhookEventType::RefundFailed))->toHaveCount(1);

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '1500.00'], 'another-key')->assertCreated();
});
