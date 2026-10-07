<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\PaymentLinks\Enums\DisputeStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\DisputeState;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundOrigin;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Payments\Filament\Resources\Payments\Pages\ViewPayment;
use App\Modules\Payments\Models\Dispute;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyDisplay;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;

/**
 * CRX-13 (plan 16, ADR-0066): the payment detail of the tenant panel shows
 * what went back to the payer (refunds) and the disputes opened against it,
 * read only: refunds are requested through the API or in Stripe, and
 * disputes are answered in the merchant's Stripe Dashboard.
 */
function capturedPaymentOf(PaymentLink $link): PaymentAttempt
{
    return Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::Succeeded)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
        'succeeded_at' => now(),
    ]));
}

it('shows the refunds and the dispute of a payment, read only', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    $attempt = capturedPaymentOf($link);

    Checkout::inTenant($link, static function () use ($attempt, $link): void {
        Refund::factory()->createOne(['payment_attempt_id' => $attempt->id, 'amount_minor' => 40_000, 'status' => RefundState::Succeeded, 'origin' => RefundOrigin::ProviderDashboard]);
        Refund::factory()->createOne(['payment_attempt_id' => $attempt->id, 'amount_minor' => 10_000, 'status' => RefundState::Pending]);
        PaymentAttempt::query()->whereKey($attempt->id)->update(['amount_refunded_minor' => 40_000]);

        $dispute = new Dispute;
        $dispute->forceFill([
            'payment_attempt_id' => $attempt->id,
            'provider_dispute_id' => 'dp_Panel0001',
            'amount_minor' => 150_000,
            'currency' => CurrencyCode::USD,
            'reason' => 'fraudulent',
            'status' => DisputeState::NeedsResponse,
            'opened_at' => now(),
            'evidence_due_by' => now()->addDays(7),
        ])->save();
        PaymentLink::query()->whereKey($link->id)->update(['dispute_status' => DisputeStatus::Open->value]);
    });

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ViewPayment::class, ['record' => $attempt->getRouteKey()])
        ->assertSee(__('payments.refunds.section'))
        ->assertSee(MoneyDisplay::format(Money::ofMinor(40_000, CurrencyCode::USD)))
        ->assertSee(MoneyDisplay::format(Money::ofMinor(10_000, CurrencyCode::USD)))
        ->assertSee(RefundState::Succeeded->label())
        ->assertSee(RefundState::Pending->label())
        ->assertSee(RefundOrigin::ProviderDashboard->label())
        ->assertSee(__('payment_links.refund_status.partial'))
        ->assertSee(__('payments.refunds.disputes'))
        ->assertSee(DisputeState::NeedsResponse->label())
        ->assertSee(__('payment_links.dispute_status.open'))
        // Never the gateway's own IDs.
        ->assertDontSee('dp_Panel0001');
});

it('shows nothing about refunds on a payment that has none', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $attempt = capturedPaymentOf(ApiTestHelpers::link($tenant));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ViewPayment::class, ['record' => $attempt->getRouteKey()])
        ->assertDontSee(__('payments.refunds.section'));
});
