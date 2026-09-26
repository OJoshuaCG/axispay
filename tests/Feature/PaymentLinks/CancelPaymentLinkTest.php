<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * POST /v1/payment_links/{id}/cancel (plan 9.1, 10.5).
 */
it('cancels an active link with an optional reason and audits it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');
    $link = ApiTestHelpers::link($tenant);

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ['reason' => 'Order changed'])
        ->assertOk()
        ->assertJsonPath('status', 'canceled')
        ->assertJsonPath('canceled_at', '2026-09-26T12:00:00Z')
        ->assertJsonPath('cancel_reason', 'Order changed');

    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Canceled);

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentLinkCanceled->value)->sole();
    // The free-text reason stays on the link; the audit keeps only its length.
    expect($audit->subject_id)->toBe($link->id)
        ->and($audit->changes['has_reason'] ?? null)->toBeTrue()
        ->and($audit->changes['reason_length'] ?? null)->toBe(13)
        ->and((string) json_encode($audit->changes))->not->toContain('Order changed');
});

it('cancels without a body', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant);

    ApiTestHelpers::raw('POST', apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ApiTestHelpers::headers($key))
        ->assertOk()
        ->assertJsonPath('cancel_reason', null);
});

it('is idempotent: canceling a canceled link answers 200 with the link', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->canceled()->state(['cancel_reason' => 'first']));

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ['reason' => 'second'])
        ->assertOk()
        ->assertJsonPath('status', 'canceled')
        ->assertJsonPath('cancel_reason', 'first');
    expect(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentLinkCanceled->value)->count())->toBe(0);
});

it('refuses to cancel paid, expired or processing links', function (PaymentLinkStatus $state, ApiErrorCode $code): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant, state: ApiTestHelpers::inStatus($state));

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', $code->value);
    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe($state);
})->with([
    [PaymentLinkStatus::Paid, ApiErrorCode::LinkNotCancelable],
    [PaymentLinkStatus::Expired, ApiErrorCode::LinkNotCancelable],
    [PaymentLinkStatus::Processing, ApiErrorCode::LinkPaymentInProgress],
]);

it('expires an active link past its expiry instead of canceling it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', ApiErrorCode::LinkNotCancelable->value);

    $fresh = ApiTestHelpers::freshLink($link->id);
    expect($fresh->status)->toBe(PaymentLinkStatus::Expired)->and($fresh->expired_at)->not->toBeNull();
});

it('validates the cancel body', function (array $body, string $param): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant);

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), $body)
        ->assertStatus(400)
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', $param);
})->with([
    [['reason' => str_repeat('r', 501)], 'reason'],
    [['reason' => 5], 'reason'],
    [['force' => true], 'force'],
]);

it('locks the link row before changing it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    DB::enableQueryLog();

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))->assertOk();

    expect(ApiTestHelpers::lockedBeforeUpdate(DB::getQueryLog(), 'payment_links'))->toBeTrue();
});

it('lets a tenant still onboarding cancel through the API', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    $tenant->forceFill(['status' => TenantStatus::PendingOnboarding])->save();

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))
        ->assertOk()
        ->assertJsonPath('status', 'canceled');
});
