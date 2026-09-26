<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Services\ApiKeyGenerator;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * API access hardening (plan 10.2, 21.3, 23; ADR-0048): closed tenants,
 * failed-authentication limit, redaction of the platform key prefix.
 */
function closeTenant(Tenant $tenant, string $closedAt): void
{
    $tenant->forceFill(['status' => TenantStatus::Closed, 'status_changed_at' => $closedAt, 'closed_at' => $closedAt])->save();
}

it('keeps a closed tenant read-only through the API for 30 days (plan §21.3)', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    closeTenant($tenant, '2026-09-01 12:00:00');

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertOk()->assertJsonCount(1, 'data');
    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$link->prefixedId()))->assertOk();

    withHeaders(ApiTestHelpers::headers($key, 'closed-create'))->postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body())
        ->assertForbidden()
        ->assertJsonPath('error.code', ApiErrorCode::TenantSuspended->value);
    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))
        ->assertForbidden()
        ->assertJsonPath('error.code', ApiErrorCode::TenantSuspended->value);
    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Active);
});

it('answers 401 once the closed tenant\'s read-only window is over', function (int $days, int $status): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    closeTenant($tenant, now()->subDays($days)->subSecond()->toDateTimeString());

    $response = withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertStatus($status);

    if ($status === 401) {
        $response->assertJsonPath('error.code', ApiErrorCode::InvalidApiKey->value);
    }
})->with([
    'day 29' => [29, 200],
    'day 30 passed' => [30, 401],
    'long closed' => [400, 401],
]);

it('makes the read-only window configurable', function (): void {
    config(['axispay.api.closed_tenant_read_days' => 5]);
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    closeTenant($tenant, '2026-09-20 12:00:00');

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertUnauthorized();
});

it('limits failed authentications per IP and never counts successful ones', function (): void {
    config(['axispay.api.failed_auth_per_minute' => 3]);
    $tenant = ApiTestHelpers::readyTenant();
    [, $valid] = ApiTestHelpers::key($tenant);
    $wrong = app(ApiKeyGenerator::class)->generate(false)->plaintext;

    foreach (range(1, 5) as $i) {
        withHeaders(ApiTestHelpers::headers($valid))->getJson(apiUrl('v1/payment_links'))->assertOk();
    }

    foreach (range(1, 3) as $i) {
        withHeaders(ApiTestHelpers::headers($wrong))->getJson(apiUrl('v1/payment_links'))->assertUnauthorized();
    }

    $blocked = withHeaders(ApiTestHelpers::headers($wrong))->getJson(apiUrl('v1/payment_links'));
    $blocked->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', ApiErrorCode::RateLimited->value)
        ->assertJsonPath('error.type', 'rate_limit_error');

    // While locked, the IP cannot try keys at all, valid ones included.
    withHeaders(ApiTestHelpers::headers($valid))->getJson(apiUrl('v1/payment_links'))->assertStatus(429);

    // Another IP is not affected.
    withHeaders(ApiTestHelpers::headers($valid))->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->getJson(apiUrl('v1/payment_links'))->assertOk();

    Carbon::setTestNow(now()->addSeconds(61));
    withHeaders(ApiTestHelpers::headers($valid))->getJson(apiUrl('v1/payment_links'))->assertOk();
});

it('refuses create and cancel with 401 once a closed tenant is past its window', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    closeTenant($tenant, '2026-08-01 12:00:00');

    withHeaders(ApiTestHelpers::headers($key, 'closed-late'))->postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body())->assertUnauthorized();
    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))->assertUnauthorized();
});

it('counts a missing header and a revoked key as failed authentications', function (): void {
    config(['axispay.api.failed_auth_per_minute' => 2]);
    $tenant = ApiTestHelpers::readyTenant();
    [, $revoked] = ApiTestHelpers::key($tenant, attributes: ['revoked_at' => now()]);
    [, $valid] = ApiTestHelpers::key($tenant);

    withHeaders(['Accept' => 'application/json'])->getJson(apiUrl('v1/payment_links'))->assertUnauthorized();
    withHeaders(ApiTestHelpers::headers($revoked))->getJson(apiUrl('v1/payment_links'))->assertUnauthorized();

    withHeaders(ApiTestHelpers::headers($valid))->getJson(apiUrl('v1/payment_links'))->assertStatus(429);
});
