<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Gateways\Stripe\Connection\PlatformOnboardingFlow;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\Shared\Ids\Ulid;
use Stripe\Exception\ApiErrorException;

/**
 * Contract tests against REAL Stripe test mode (plan 12.1, 26.1). Skipped
 * unless the keys are exported in the shell (never read from .env):
 *
 *   STRIPE_TEST_SECRET=sk_test_... (platform, Connect enabled)
 *   STRIPE_CONTRACT_RESTRICTED_KEY=rk_test_... and
 *   STRIPE_CONTRACT_PUBLISHABLE_KEY=pk_test_... (a merchant account, api_key)
 *   STRIPE_CONTRACT_OTHER_PUBLISHABLE_KEY=pk_test_... (optional: another account)
 *
 *   ./vendor/bin/pest --group=stripe
 *
 * They confirm what the docs leave open (ADR-0047): the Standard-equivalent
 * controller combination, the PII-token check of pk <-> rk, and that Stripe
 * answers the permission probes with 403 before validating parameters.
 */
function contractEnv(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

/** False when any of the variables is missing or not a test-mode key. */
function contractReady(string ...$names): bool
{
    foreach ($names as $name) {
        if (! str_contains((string) contractEnv($name), '_test_')) {
            return false;
        }
    }

    return true;
}

const CONTRACT_SKIP = 'Export the test-mode keys listed in tests/Contract/StripeContractTest.php to run the Stripe contract tests.';

beforeEach(function (): void {
    config(['services.stripe.test.secret' => contractEnv('STRIPE_TEST_SECRET')]);
});

it('creates a Standard-equivalent MX account and an onboarding link', function (): void {
    $connection = (new GatewayConnection)->forceFill([
        'id' => Ulid::generate(),
        'tenant_id' => Ulid::generate(),
        'livemode' => false,
        'connection_method' => ConnectionMethod::PlatformOnboarding,
    ]);

    $flow = app(PlatformOnboardingFlow::class);
    $accountId = $flow->createAccount($connection, 'MX');
    $connection->forceFill(['provider_account_id' => $accountId]);

    try {
        $account = app(StripeGateway::class)->retrieveAccount($connection);
        $link = $flow->createOnboardingLink($connection, 'https://example.com/return', 'https://example.com/refresh', Ulid::generate());

        expect($account->country)->toBe('MX')
            ->and($account->chargesEnabled)->toBeFalse()
            ->and($link)->toStartWith('https://connect.stripe.com/');

        $raw = app(StripeClientFactory::class)->platform(false)->client->accounts->retrieve($accountId);
        expect($raw->controller?->toArray())->toMatchArray([
            'fees' => ['payer' => 'account'],
            'losses' => ['payments' => 'stripe'],
            'requirement_collection' => 'stripe',
            'stripe_dashboard' => ['type' => 'full'],
        ]);
    } finally {
        // Test-mode accounts can always be deleted (live ones cannot).
        app(StripeClientFactory::class)->platform(false)->client->accounts->delete($accountId);
    }
})->group('stripe')->skip(! contractReady('STRIPE_TEST_SECRET'), CONTRACT_SKIP);

it('validates a real restricted + publishable key pair', function (): void {
    $credentials = ApiKeyCredentials::from((string) contractEnv('STRIPE_CONTRACT_RESTRICTED_KEY'), (string) contractEnv('STRIPE_CONTRACT_PUBLISHABLE_KEY'));

    try {
        $result = app(ApiKeyFlow::class)->validate($credentials, false, Ulid::generate());
        expect($result->granted)->toContain('payment_intent_write', 'charge_write', 'token_read');
    } catch (ApiKeyValidationException $e) {
        // A key without every permission is still a valid contract outcome, as
        // long as Stripe let us classify it (no transport or auth failure).
        expect($e->rejection)->toBeIn([ApiKeyRejection::MissingPermissions, ApiKeyRejection::ExcessivePermissionsNotConfirmed, ApiKeyRejection::CountryNotAllowed]);
    }
})->group('stripe')->skip(! contractReady('STRIPE_TEST_SECRET', 'STRIPE_CONTRACT_RESTRICTED_KEY', 'STRIPE_CONTRACT_PUBLISHABLE_KEY'), CONTRACT_SKIP);

it('detects a publishable key of another account', function (): void {
    $credentials = ApiKeyCredentials::from((string) contractEnv('STRIPE_CONTRACT_RESTRICTED_KEY'), (string) contractEnv('STRIPE_CONTRACT_OTHER_PUBLISHABLE_KEY'));

    expect(thrownBy(ApiKeyValidationException::class, fn () => app(ApiKeyFlow::class)->validate($credentials, false, Ulid::generate()))->rejection)
        ->toBe(ApiKeyRejection::PublishableKeyOtherAccount);
})->group('stripe')->skip(! contractReady('STRIPE_TEST_SECRET', 'STRIPE_CONTRACT_RESTRICTED_KEY', 'STRIPE_CONTRACT_OTHER_PUBLISHABLE_KEY'), CONTRACT_SKIP);

it('answers a permission probe with 403 or 400, never by creating anything', function (): void {
    $context = app(StripeClientFactory::class)->direct((string) contractEnv('STRIPE_CONTRACT_RESTRICTED_KEY'), (string) contractEnv('STRIPE_CONTRACT_PUBLISHABLE_KEY'));

    foreach (['/v1/payouts', '/v1/transfers', '/v1/payment_intents', '/v1/refunds'] as $path) {
        $e = thrownBy(ApiErrorException::class, fn () => $context->client->rawRequest('post', $path, [], ['idempotency_key' => 'axispay-contract-'.Ulid::generate()]));

        expect($e->getHttpStatus())->toBeIn([400, 403], "POST {$path} answered {$e->getHttpStatus()}.");
    }
})->group('stripe')->skip(! contractReady('STRIPE_TEST_SECRET', 'STRIPE_CONTRACT_RESTRICTED_KEY', 'STRIPE_CONTRACT_PUBLISHABLE_KEY'), CONTRACT_SKIP);

it('creates and deletes a webhook endpoint with the restricted key', function (): void {
    $context = app(StripeClientFactory::class)->direct((string) contractEnv('STRIPE_CONTRACT_RESTRICTED_KEY'), (string) contractEnv('STRIPE_CONTRACT_PUBLISHABLE_KEY'));
    $id = Ulid::generate();
    $flow = app(ApiKeyFlow::class);

    $endpoint = $flow->createWebhookEndpoint($context, 'https://example.com/webhooks/stripe/direct/'.$id, $id, 'axispay-contract-endpoint-'.$id);

    expect($endpoint['secret'])->toStartWith('whsec_')
        ->and($flow->deleteWebhookEndpoint($context, $endpoint['id']))->toBeTrue();
})->group('stripe')->skip(! contractReady('STRIPE_TEST_SECRET', 'STRIPE_CONTRACT_RESTRICTED_KEY', 'STRIPE_CONTRACT_PUBLISHABLE_KEY'), CONTRACT_SKIP);
