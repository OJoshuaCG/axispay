<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Payments\Filament\Resources\Payments\Pages\ListPayments;
use App\Modules\Payments\Filament\Resources\Payments\Pages\ViewPayment;
use App\Modules\Payments\Filament\Resources\Payments\PaymentResource;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\get;

/**
 * Payments in the tenant panel (ADR-0059, brought forward from Phase 8):
 * `payments:read`, read-only, one row per payment attempt of the current
 * mode, filters, search and the detail's timeline.
 */

/**
 * An attempt of `$link`, written directly (the payment flow is tested on its own).
 *
 * @param  array<string, mixed>  $attributes
 */
function panelAttempt(PaymentLink $link, PaymentAttemptStatus $status = PaymentAttemptStatus::Succeeded, array $attributes = []): PaymentAttempt
{
    return CheckoutTestHelpers::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus($status)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => CheckoutTestHelpers::connectionOf($link)->id,
        'card_brand' => 'visa',
        'card_last4' => '4242',
        'card_country' => 'MX',
        ...$attributes,
    ]));
}

it('is only reachable with payments:read', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $attempt = panelAttempt(ApiTestHelpers::link($tenant));

    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));
    get(appUrl('/payments'))->assertForbidden();
    get(appUrl('/payments/'.$attempt->id))->assertForbidden();
    expect(PaymentResource::canViewAny())->toBeFalse();

    foreach ([SystemRole::Viewer, SystemRole::Finance] as $role) {
        actingAsTenantUser(tenantUser($tenant, [$role]));
        get(appUrl('/payments'))->assertOk()->assertSee(__('payments.resource.plural'), false);
        get(appUrl('/payments/'.$attempt->id))->assertOk();
    }
});

it('lists the payments of the current mode, newest first, with amount, card and validation', function (string $locale): void {
    $tenant = ApiTestHelpers::readyTenant();
    GatewayTestHelpers::connection($tenant, livemode: true);

    app()->setLocale($locale);
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['description' => 'Order A-1029', 'client_reference_id' => 'A-1029']));
    $older = panelAttempt($link, PaymentAttemptStatus::Failed, ['created_at' => now()->subDay(), 'card_last4' => '0002']);
    $newer = panelAttempt($link, PaymentAttemptStatus::Succeeded, [
        'amount_minor' => 1_234_567,
        'currency' => CurrencyCode::MXN,
        'validation_outcome' => ValidationOutcome::Approved,
    ]);
    $live = panelAttempt(ApiTestHelpers::link($tenant, livemode: true));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]), livemode: false);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$live])
        ->assertSee('Order A-1029')
        ->assertSee(MoneyDisplay::format(Money::ofMinor(1_234_567, CurrencyCode::MXN)))
        ->assertSee('Visa •••• 4242')
        ->assertSee(ValidationOutcome::Approved->label())
        ->assertSee(PaymentAttemptStatus::Failed->label())
        ->assertSee(__('payments.resource.page.subheading.test'));
})->with(['en', 'es']);

it('filters by status, currency and date, and searches by link or payment ID', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $order = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['description' => 'Order A-1029', 'client_reference_id' => 'A-1029']));
    $other = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['description' => 'Invoice 77']));
    $succeeded = panelAttempt($order, PaymentAttemptStatus::Succeeded, ['currency' => CurrencyCode::MXN, 'original_currency' => CurrencyCode::MXN]);
    $failed = panelAttempt($other, PaymentAttemptStatus::Failed, ['created_at' => now()->subDays(10)]);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Finance]));

    Livewire::test(ListPayments::class)
        ->filterTable('status', PaymentAttemptStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$succeeded])
        ->resetTableFilters()
        ->filterTable('currency', CurrencyCode::MXN->value)
        ->assertCanSeeTableRecords([$succeeded])->assertCanNotSeeTableRecords([$failed])
        ->resetTableFilters()
        ->filterTable('created', ['from' => now()->subDays(2)->toDateString(), 'until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$succeeded])->assertCanNotSeeTableRecords([$failed])
        ->resetTableFilters()
        ->searchTable('A-1029')
        ->assertCanSeeTableRecords([$succeeded])->assertCanNotSeeTableRecords([$failed])
        ->searchTable('Invoice')
        ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$succeeded])
        ->searchTable($failed->prefixedId())
        ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$succeeded]);
});

it('shows the detail with a link to its payment link and the timeline', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['description' => 'Order A-1029']));
    $attempt = panelAttempt($link, PaymentAttemptStatus::Succeeded, [
        'authorized_at' => now()->subMinutes(2),
        'succeeded_at' => now()->subMinute(),
        'validation_outcome' => ValidationOutcome::Approved,
    ]);
    CheckoutTestHelpers::inTenant($link, static function () use ($link, $attempt): void {
        $call = new ValidationCall;
        $call->forceFill([
            'livemode' => false,
            'payment_link_id' => $link->id,
            'payment_attempt_id' => $attempt->id,
            'attempt_number' => 1,
            'request_payload' => [],
            'outcome' => ValidationCallOutcome::Approved,
            'duration_ms' => 210,
        ])->save();

        DB::transaction(static fn () => app(DomainEventRecorder::class)->record(DomainEventType::PaymentSucceeded, 'payment', $attempt->id, ['payment' => ['id' => $attempt->prefixedId()]]));
    });

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));

    Livewire::test(ViewPayment::class, ['record' => $attempt->getRouteKey()])
        ->assertSee(MoneyDisplay::format($attempt->money()))
        ->assertSee('Order A-1029')
        ->assertSee(PaymentLinkResource::getUrl('view', ['record' => $link]), false)
        ->assertSee($attempt->prefixedId())
        ->assertSee(__('payments.timeline.started'))
        ->assertSee(__('payments.timeline.authorized'))
        ->assertSee(__('payments.timeline.validation', ['outcome' => ValidationCallOutcome::Approved->label()]))
        ->assertSee(__('payments.timeline.captured'))
        ->assertSee(__('payments.timeline.event', ['type' => 'payment.succeeded']));
});

it('shows the declines only when there were some, and keeps the timeline icons decorative', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    $clean = panelAttempt($link, PaymentAttemptStatus::Succeeded, ['authorized_at' => now()->subMinutes(2), 'succeeded_at' => now()->subMinute()]);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));

    $html = Livewire::test(ViewPayment::class, ['record' => $clean->getRouteKey()])
        ->assertDontSee(__('payments.attempts.failures'))
        ->html();

    preg_match('/<ol[^>]*data-payment-timeline[^>]*>(.*?)<\/ol>/s', $html, $timeline);
    preg_match_all('/<svg\b[^>]*>/', $timeline[1] ?? '', $icons);
    expect($icons[0])->not->toBeEmpty();

    foreach ($icons[0] as $icon) {
        expect($icon)->toContain('aria-hidden="true"');
    }

    $declined = panelAttempt($link, PaymentAttemptStatus::Failed, ['failure_count' => 2]);

    Livewire::test(ViewPayment::class, ['record' => $declined->getRouteKey()])
        ->assertSee(__('payments.attempts.failures'));
});

it('hides the validation calls from the timeline without webhooks:manage', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    $attempt = panelAttempt($link, PaymentAttemptStatus::Canceled, ['canceled_at' => now(), 'validation_outcome' => ValidationOutcome::Rejected]);
    CheckoutTestHelpers::inTenant($link, static function () use ($link, $attempt): void {
        $call = new ValidationCall;
        $call->forceFill([
            'livemode' => false,
            'payment_link_id' => $link->id,
            'payment_attempt_id' => $attempt->id,
            'request_payload' => [],
            'outcome' => ValidationCallOutcome::Rejected,
        ])->save();
    });

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ViewPayment::class, ['record' => $attempt->getRouteKey()])
        ->assertSee(__('payments.timeline.released'))
        ->assertSee(__('payments.timeline.released_rejected'))
        ->assertDontSee(__('payments.timeline.validation', ['outcome' => ValidationCallOutcome::Rejected->label()]));
});

it('links each attempt of a link to its payment detail', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    $attempt = panelAttempt($link);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    get(appUrl('/payment-links/'.$link->id))->assertOk()->assertSee(PaymentResource::getUrl('view', ['record' => $attempt]), false);
});
