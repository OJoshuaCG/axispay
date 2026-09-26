<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use Illuminate\Support\Carbon;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * Rate limit per API key (plan 10.1, ADR-0048): 100 requests per minute by
 * default, RateLimit-* headers on every response, 429 with Retry-After.
 */
it('limits requests per key and tells the client how long to wait', function (): void {
    config(['axispay.api.rate_limit_per_minute.test' => 3]);
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    [, $otherKey] = ApiTestHelpers::key($tenant);

    foreach ([2, 1, 0] as $remaining) {
        withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))
            ->assertOk()
            ->assertHeader('RateLimit-Limit', '3')
            ->assertHeader('RateLimit-Remaining', (string) $remaining)
            ->assertHeader('RateLimit-Reset');
    }

    $response = withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'));

    $response->assertStatus(429)
        ->assertJsonPath('error.code', ApiErrorCode::RateLimited->value)
        ->assertJsonPath('error.type', 'rate_limit_error')
        ->assertHeader('Retry-After')
        ->assertHeader('RateLimit-Remaining', '0');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    // Another key of the same tenant has its own budget.
    withHeaders(ApiTestHelpers::headers($otherKey))->getJson(apiUrl('v1/payment_links'))->assertOk();
});

it('uses 100 requests per minute in both modes by default', function (): void {
    expect(config('axispay.api.rate_limit_per_minute'))->toBe(['live' => 100, 'test' => 100]);

    $tenant = ApiTestHelpers::readyTenant(livemode: true);
    [, $key] = ApiTestHelpers::key($tenant, livemode: true);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertHeader('RateLimit-Limit', '100');
});

it('counts error responses and adds the headers to them', function (): void {
    config(['axispay.api.rate_limit_per_minute.test' => 1]);
    [, $key] = ApiTestHelpers::key(ApiTestHelpers::readyTenant());

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/plink_nope'))
        ->assertNotFound()
        ->assertHeader('RateLimit-Remaining', '0');
    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertStatus(429);
});

it('counts a request refused for scope, and adds the headers to that refusal', function (): void {
    config(['axispay.api.rate_limit_per_minute.test' => 1]);
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant, scopes: [ApiScope::LinksCreate]);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))
        ->assertForbidden()
        ->assertJsonPath('error.code', ApiErrorCode::InsufficientScope->value)
        ->assertHeader('RateLimit-Limit', '1')
        ->assertHeader('RateLimit-Remaining', '0');

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertStatus(429);
});

it('opens a new window after a minute', function (): void {
    config(['axispay.api.rate_limit_per_minute.test' => 1]);
    Carbon::setTestNow('2026-09-26 12:00:00');
    [, $key] = ApiTestHelpers::key(ApiTestHelpers::readyTenant());

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertOk();
    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertStatus(429);

    Carbon::setTestNow('2026-09-26 12:01:01');
    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertOk()->assertHeader('RateLimit-Remaining', '0');
});

it('applies a live-only override to live keys only', function (): void {
    config(['axispay.api.rate_limit_per_minute.live' => 5]);
    $tenant = ApiTestHelpers::readyTenant();
    GatewayTestHelpers::connection($tenant, livemode: true);
    [, $test] = ApiTestHelpers::key($tenant, livemode: false);
    [, $live] = ApiTestHelpers::key($tenant, livemode: true);

    withHeaders(ApiTestHelpers::headers($live))->getJson(apiUrl('v1/payment_links'))->assertHeader('RateLimit-Limit', '5');
    withHeaders(ApiTestHelpers::headers($test))->getJson(apiUrl('v1/payment_links'))->assertHeader('RateLimit-Limit', '100');
});
