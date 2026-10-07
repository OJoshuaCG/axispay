<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayCredentialsKeyException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

/**
 * Plan 12.4.1, 12.2, 23.2: the client factory builds the right call context
 * per connection method, pins the API version, sends idempotency keys, and
 * merchant credentials are encrypted with the dedicated key.
 */
it('calls Stripe with the platform key and Stripe-Account for Connect methods', function (ConnectionMethod $method): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->state(['connection_method' => $method, 'provider_account_id' => 'acct_Connect0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Connect0001'));

    $context = app(StripeClientFactory::class)->for($connection);
    $account = app(StripeGateway::class)->retrieveAccount($connection);
    $request = stripeHttp()->requestsTo('get', '/v1/account')[0];

    expect($context->options('op-1'))->toBe(['stripe_account' => 'acct_Connect0001', 'idempotency_key' => 'op-1'])
        ->and($context->publishableKey)->toBe('pk_test_platform')
        ->and($request['headers']['stripe-account'])->toBe('acct_Connect0001')
        ->and($request['headers']['authorization'])->toBe('Bearer sk_test_platformdummy')
        ->and($request['headers']['stripe-version'])->toBe('2026-08-26.dahlia')
        ->and($account->providerAccountId)->toBe('acct_Connect0001')
        ->and($account->chargesEnabled)->toBeTrue();
})->with([ConnectionMethod::PlatformOnboarding, ConnectionMethod::OAuth]);

it('calls Stripe with the merchant restricted key and no Stripe-Account for api_key', function (): void {
    $secret = GatewayTestHelpers::restrictedKey();
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey($secret)->state(['provider_account_id' => 'acct_Direct0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Direct0001'));

    $context = app(StripeClientFactory::class)->for($connection);
    app(StripeGateway::class)->retrieveAccount($connection);
    $request = stripeHttp()->requestsTo('get', '/v1/account')[0];

    expect($context->options('op-2'))->toBe(['idempotency_key' => 'op-2'])
        ->and($context->isConnect())->toBeFalse()
        ->and($request['headers'])->not->toHaveKey('stripe-account')
        ->and($request['headers']['authorization'])->toBe('Bearer '.$secret)
        ->and($request['headers']['stripe-version'])->toBe('2026-08-26.dahlia')
        ->and(app(StripeGateway::class)->clientConfig($connection)->accountId)->toBeNull();
});

it('never serializes a call context or merchant credentials', function (): void {
    $secret = GatewayTestHelpers::restrictedKey();
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey($secret));
    $credentials = ApiKeyCredentials::from($secret, GatewayTestHelpers::publishableKey());

    expect(fn () => serialize(app(StripeClientFactory::class)->for($connection)))->toThrow(LogicException::class)
        ->and(fn () => serialize($credentials))->toThrow(LogicException::class)
        ->and(print_r($credentials, true))->not->toContain($secret)
        ->and(json_encode($connection->toArray()))->not->toContain($connection->credentials_secret ?? 'missing');
});

it('maps revoked keys to an authentication error without Stripe details', function (): void {
    $secret = GatewayTestHelpers::restrictedKey();
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey($secret));
    stripeHttp()->error('get', '/v1/account', 401, 'invalid_request_error');

    $e = thrownBy(GatewayAuthenticationException::class, fn () => app(StripeGateway::class)->retrieveAccount($connection));

    expect($e->httpStatus)->toBe(401)
        ->and($e->getPrevious())->toBeNull()
        ->and((string) $e)->not->toContain($secret)
        ->and($e->getMessage())->not->toContain('rk_');
});

it('encrypts credentials with the dedicated versioned key, never APP_KEY', function (): void {
    $encrypter = app(GatewayCredentialsEncrypter::class);
    $encrypted = $encrypter->encrypt('rk_test_secretvalue');

    expect($encrypted->keyVersion)->toBe(1)
        ->and($encrypted->ciphertext)->not->toContain('secretvalue')
        ->and($encrypter->decrypt($encrypted->ciphertext, 1))->toBe('rk_test_secretvalue')
        ->and(fn () => decrypt($encrypted->ciphertext))->toThrow(DecryptException::class);

    config(['axispay.gateway_credentials.key' => config('app.key')]);
    expect(fn () => app(GatewayCredentialsEncrypter::class)->encrypt('x'))->toThrow(GatewayCredentialsKeyException::class, 'different from APP_KEY');

    config(['axispay.gateway_credentials.key' => null]);
    expect(fn () => app(GatewayCredentialsEncrypter::class)->encrypt('x'))->toThrow(GatewayCredentialsKeyException::class, 'not configured');
});

it('rotates the credentials key and keeps old versions readable meanwhile', function (): void {
    $secret = GatewayTestHelpers::restrictedKey();
    $tenant = activeTenant();
    $connection = GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->apiKey($secret));
    $oldKey = config()->string('axispay.gateway_credentials.key');

    config([
        'axispay.gateway_credentials.key' => 'base64:'.base64_encode(random_bytes(32)),
        'axispay.gateway_credentials.key_version' => 2,
        'axispay.gateway_credentials.previous_keys' => '1:'.$oldKey,
    ]);

    expect(app(GatewayCredentialsEncrypter::class)->decrypt((string) $connection->credentials_secret, 1))->toBe($secret);

    artisanCommand('axispay:rotate-gateway-credentials-key')->assertSuccessful();

    $rotated = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): GatewayConnection => GatewayConnection::query()->findOrFail($connection->id));
    config(['axispay.gateway_credentials.previous_keys' => '']);

    expect($rotated->credentials_key_version)->toBe(2)
        ->and(app(GatewayCredentialsEncrypter::class)->decrypt((string) $rotated->credentials_secret, 2))->toBe($secret)
        ->and(app(GatewayCredentialsEncrypter::class)->decrypt((string) $rotated->provider_webhook_secret, 2))->toStartWith('whsec_');
});
