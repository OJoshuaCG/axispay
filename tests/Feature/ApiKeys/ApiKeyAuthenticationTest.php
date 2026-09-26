<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Services\ApiKeyAuthenticator;
use App\Modules\ApiKeys\Services\ApiKeyGenerator;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/**
 * API key authentication and scopes (plan 10.2, 6.3; critical case 11).
 */
it('answers 401 invalid_api_key for a missing, malformed, unknown, revoked or expired key', function (Closure $credential): void {
    $tenant = ApiTestHelpers::readyTenant();

    $header = $credential($tenant);
    $headers = $header === null ? ['Accept' => 'application/json'] : ['Authorization' => $header, 'Accept' => 'application/json'];

    withHeaders($headers)->getJson(apiUrl('v1/payment_links'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertJsonPath('error.code', ApiErrorCode::InvalidApiKey->value)
        ->assertJsonPath('error.type', 'authentication_error')
        ->assertJsonPath('error.message', 'Invalid API key provided. Send it as "Authorization: Bearer axp_test_..." or "axp_live_...".');
})->with([
    'missing' => static fn (): ?string => null,
    'not bearer' => static fn (): string => 'Basic dXNlcjpwYXNz',
    'malformed' => static fn (): string => 'Bearer axp_test_short',
    'Stripe-looking key' => static fn (): string => 'Bearer sk_test_'.str_repeat('a', 43),
    'unknown well-formed key' => static fn (): string => 'Bearer '.app(ApiKeyGenerator::class)->generate(false)->plaintext,
    'revoked' => static fn (Tenant $tenant): string => 'Bearer '.ApiTestHelpers::key($tenant, attributes: ['revoked_at' => now()])[1],
    'expired' => static fn (Tenant $tenant): string => 'Bearer '.ApiTestHelpers::key($tenant, attributes: ['expires_at' => now()->subSecond()])[1],
]);

it('never authenticates a key whose prefix names the other mode', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    // A row stored as live whose plaintext carries the test prefix: the
    // prefix decides the mode (plan 10.2), so it is refused.
    $plaintext = app(ApiKeyGenerator::class)->generate(false)->plaintext;
    ApiTestHelpers::key($tenant, livemode: true, attributes: [
        'key_hash' => ApiKeyGenerator::hash($plaintext),
    ]);

    expect(app(ApiKeyAuthenticator::class)->authenticate($plaintext))->toBeNull();
    withHeaders(ApiTestHelpers::headers($plaintext))->getJson(apiUrl('v1/payment_links'))->assertUnauthorized();
});

it('authenticates a valid key and sets the tenant and mode from it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    [, $key] = ApiTestHelpers::key($tenant, livemode: false);
    ApiTestHelpers::link($tenant, livemode: false);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.livemode', false);
});

it('keeps test keys away from live links and live keys away from test links (case 11)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $live = ApiTestHelpers::link($tenant, livemode: true);
    $test = ApiTestHelpers::link($tenant, livemode: false);
    [, $testKey] = ApiTestHelpers::key($tenant, livemode: false);
    [, $liveKey] = ApiTestHelpers::key($tenant, livemode: true);

    withHeaders(ApiTestHelpers::headers($testKey))->getJson(apiUrl('v1/payment_links/'.$live->prefixedId()))
        ->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
    withHeaders(ApiTestHelpers::headers($testKey))->postJson(apiUrl('v1/payment_links/'.$live->prefixedId().'/cancel'))
        ->assertNotFound();
    withHeaders(ApiTestHelpers::headers($liveKey))->getJson(apiUrl('v1/payment_links/'.$test->prefixedId()))
        ->assertNotFound();

    withHeaders(ApiTestHelpers::headers($testKey))->getJson(apiUrl('v1/payment_links'))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $test->prefixedId());
    withHeaders(ApiTestHelpers::headers($liveKey))->getJson(apiUrl('v1/payment_links'))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live->prefixedId());
});

it('requires the scope of each endpoint', function (string $method, string $path, ApiScope $scope): void {
    $tenant = ApiTestHelpers::readyTenant();

    $link = ApiTestHelpers::link($tenant);
    $others = array_values(array_filter(ApiScope::cases(), static fn (ApiScope $s): bool => $s !== $scope));
    [, $withoutScope] = ApiTestHelpers::key($tenant, scopes: $others);
    $url = apiUrl(str_replace('{id}', $link->prefixedId(), $path));

    withHeaders(ApiTestHelpers::headers($withoutScope, 'idem-scope'))->json($method, $url, $method === 'POST' ? ApiTestHelpers::body() : [])
        ->assertForbidden()
        ->assertJsonPath('error.code', ApiErrorCode::InsufficientScope->value)
        ->assertJsonPath('error.type', 'permission_error');

    [, $withScope] = ApiTestHelpers::key($tenant, scopes: [$scope]);

    expect(withHeaders(ApiTestHelpers::headers($withScope, 'idem-scope-ok'))->json($method, $url, $method === 'POST' && str_ends_with($path, 'payment_links') ? ApiTestHelpers::body() : [])->status())
        ->toBeIn([200, 201]);
})->with([
    'create' => ['POST', 'v1/payment_links', ApiScope::LinksCreate],
    'list' => ['GET', 'v1/payment_links', ApiScope::LinksRead],
    'show' => ['GET', 'v1/payment_links/{id}', ApiScope::LinksRead],
    'cancel' => ['POST', 'v1/payment_links/{id}/cancel', ApiScope::LinksCancel],
]);

it('records last use at most once per minute', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    Carbon::setTestNow('2026-09-26 10:00:00');
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertOk();
    $first = ApiKey::query()->withoutGlobalScopes()->findOrFail($apiKey->id);
    expect($first->last_used_at?->toDateTimeString())->toBe('2026-09-26 10:00:00')
        ->and($first->last_used_ip)->toBe('127.0.0.1');

    Carbon::setTestNow('2026-09-26 10:00:30');
    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'))->assertOk();
    expect(ApiKey::query()->withoutGlobalScopes()->findOrFail($apiKey->id)->last_used_at?->toDateTimeString())->toBe('2026-09-26 10:00:00');
});

it('stores only the hash and a masked form of the key', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    [$apiKey, $key] = ApiTestHelpers::key($tenant, livemode: true);

    expect($key)->toMatch('/^axp_live_[0-9A-Za-z]{43}$/')
        ->and($apiKey->key_hash)->toBe(hash('sha256', $key))
        ->and($apiKey->maskedKey())->toBe(substr($key, 0, 13).'…'.substr($key, -4))
        ->and($apiKey->toArray())->not->toHaveKey('key_hash')
        ->and(json_encode($apiKey->toArray()))->not->toContain(substr($key, 13, 20));
});

it('generates distinct high-entropy keys per mode', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $generator = app(ApiKeyGenerator::class);
    $keys = array_map(static fn (): string => $generator->generate(false)->plaintext, range(1, 50));

    expect(array_unique($keys))->toHaveCount(50)
        ->and($generator->generate(true)->plaintext)->toStartWith('axp_live_')
        ->and($keys[0])->toStartWith('axp_test_')
        ->and(ApiKeyGenerator::modeOf($keys[0]))->toBeFalse();
});

it('answers every API response with a Request-Id', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    getJson(apiUrl('v1/payment_links'))->assertUnauthorized()->assertHeader('Request-Id');
});
