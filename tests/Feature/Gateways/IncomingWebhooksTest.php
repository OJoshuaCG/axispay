<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\DisconnectReason;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

use function Pest\Laravel\call;

/**
 * Plan 14 and 26.2 case 3: signature on the raw body, one row per Stripe
 * event, 200 right away, processing in a job that re-reads the object from
 * Stripe and applies it through the Gateways actions.
 */
beforeEach(function (): void {
    Notification::fake();
});

/**
 * @param  array<mixed>  $event
 * @return TestResponse<Response>
 */
function postWebhook(string $path, array $event, string $secret): TestResponse
{
    [$body, $signature] = StripeFixtures::signed($event, $secret);

    return call('POST', apiUrl($path), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
}

/**
 * @return list<ProviderEvent>
 */
function storedEvents(): array
{
    return array_values(ProviderEvent::query()->withoutGlobalScopes()->orderBy('received_at')->get()->all());
}

/**
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function inTenant(GatewayConnection $connection, Closure $callback): mixed
{
    return app(TenantContext::class)->runAsTenant($connection->tenant_id, $connection->livemode, $callback);
}

it('processes a signed account.updated once and re-reads the account from Stripe', function (): void {
    $tenant = Tenant::factory()->status(TenantStatus::PendingOnboarding)->create();
    $connection = GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->onboarding()->state(['provider_account_id' => 'acct_Hook0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Hook0001', chargesEnabled: true));
    $event = StripeFixtures::load('account.updated', ['event' => 'evt_Updated0001', 'account' => 'acct_Hook0001', 'livemode' => false]);

    postWebhook('/webhooks/stripe/connect/test', $event, 'whsec_testconnectsecret')->assertOk()->assertExactJson(['received' => true]);
    postWebhook('/webhooks/stripe/connect/test', $event, 'whsec_testconnectsecret')->assertOk();

    $stored = storedEvents();

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->status)->toBe(ProviderEventStatus::Processed)
        ->and($stored[0]->tenant_id)->toBe($tenant->id)
        ->and($stored[0]->gateway_connection_id)->toBe($connection->id)
        ->and($stored[0]->attempts)->toBe(1)
        ->and(json_decode($stored[0]->payload, true))->toBe($event)
        ->and(stripeHttp()->requestsTo('get', '/v1/account'))->toHaveCount(1)
        ->and(stripeHttp()->requestsTo('get', '/v1/account')[0]['headers']['stripe-account'])->toBe('acct_Hook0001')
        ->and(inTenant($connection, static fn () => $connection->refresh()->status))->toBe(ConnectionStatus::Active)
        ->and($tenant->refresh()->status)->toBe(TenantStatus::Active);
});

it('trusts Stripe over the payload (ADR-017)', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_Hook0002']));
    // The payload says charges are enabled; Stripe now says they are not.
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Hook0002', chargesEnabled: false));

    postWebhook('/webhooks/stripe/connect/test', StripeFixtures::load('account.updated', ['event' => 'evt_Stale0001', 'account' => 'acct_Hook0002', 'livemode' => false]), 'whsec_testconnectsecret')->assertOk();

    expect(inTenant($connection, static fn () => $connection->refresh()->status))->toBe(ConnectionStatus::Restricted);
});

it('rejects an invalid signature with the API error format and stores nothing', function (): void {
    $event = StripeFixtures::load('account.updated', ['event' => 'evt_Forged0001', 'account' => 'acct_Hook0001', 'livemode' => false]);
    $logs = captureDefaultLog();

    postWebhook('/webhooks/stripe/connect/test', $event, 'whsec_notthesecret')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'parameter_invalid')
        ->assertJsonStructure(['error' => ['type', 'code', 'message', 'param', 'request_id']]);

    call('POST', apiUrl('/webhooks/stripe/connect/test'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);

    expect(storedEvents())->toBe([])
        ->and(collect($logs->getRecords())->filter(static fn ($record): bool => ($record->context['metric'] ?? null) === 'stripe_webhook_signature_failures'))->toHaveCount(2);
});

it('verifies with the secret of the mode in the URL and routes by the event mode', function (): void {
    $live = GatewayTestHelpers::connection(activeTenant(), livemode: true, state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_Live0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Live0001'));
    $event = StripeFixtures::load('account.updated', ['event' => 'evt_Live0001', 'account' => 'acct_Live0001', 'livemode' => true]);

    postWebhook('/webhooks/stripe/connect/live', $event, 'whsec_testconnectsecret')->assertStatus(400);
    postWebhook('/webhooks/stripe/connect/live', $event, 'whsec_liveconnectsecret')->assertOk();

    expect(storedEvents()[0]->livemode)->toBeTrue()
        ->and(storedEvents()[0]->gateway_connection_id)->toBe($live->id);
});

it('stores events of unknown accounts as unroutable platform rows and alerts', function (): void {
    $logs = captureDefaultLog();

    postWebhook('/webhooks/stripe/connect/test', StripeFixtures::load('account.updated', ['event' => 'evt_Unknown0001', 'account' => 'acct_Nobody0001', 'livemode' => false]), 'whsec_testconnectsecret')->assertOk();

    $stored = storedEvents()[0];

    expect($stored->status)->toBe(ProviderEventStatus::Unroutable)
        ->and($stored->tenant_id)->toBeNull()
        ->and(jsonArray($stored->payload)['axispay_reduced'] ?? null)->toBeTrue()
        ->and($stored->payload)->not->toContain('requirements')
        ->and(stripeHttp()->requests)->toBe([])
        ->and($logs->hasAlertThatContains('Unroutable gateway event'))->toBeTrue();
});

it('disconnects on account.application.deauthorized only once Stripe denies access', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_Gone0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Gone0001'));

    postWebhook('/webhooks/stripe/connect/test', StripeFixtures::load('account.application.deauthorized', ['event' => 'evt_Deauth0001', 'account' => 'acct_Gone0001', 'livemode' => false]), 'whsec_testconnectsecret')->assertOk();

    expect(storedEvents()[0]->status)->toBe(ProviderEventStatus::Ignored)
        ->and(inTenant($connection, static fn () => $connection->refresh()->status))->toBe(ConnectionStatus::Active);

    stripeHttp()->error('get', '/v1/account', 403, 'invalid_request_error', 'account_invalid');
    postWebhook('/webhooks/stripe/connect/test', StripeFixtures::load('account.application.deauthorized', ['event' => 'evt_Deauth0002', 'account' => 'acct_Gone0001', 'livemode' => false]), 'whsec_testconnectsecret')->assertOk();

    $connection = inTenant($connection, static fn () => $connection->refresh());

    expect(storedEvents()[1]->status)->toBe(ProviderEventStatus::Processed)
        ->and($connection->status)->toBe(ConnectionStatus::Disconnected)
        ->and($connection->disconnect_reason)->toBe(DisconnectReason::Deauthorized);
});

it('ignores events it does not handle yet', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_Pay0001']));
    $event = StripeFixtures::load('payment_intent.succeeded', ['event' => 'evt_Pi0001', 'livemode' => false]);
    $event['account'] = 'acct_Pay0001';

    postWebhook('/webhooks/stripe/connect/test', $event, 'whsec_testconnectsecret')->assertOk();

    $stored = storedEvents()[0];
    $payload = jsonArray($stored->payload);

    // Plan 14.4: filtered cheaply, no job, and no object data kept.
    expect($stored->status)->toBe(ProviderEventStatus::Ignored)
        ->and($stored->tenant_id)->toBe($connection->tenant_id)
        ->and($stored->processed_at)->not->toBeNull()
        ->and($payload)->toMatchArray(['id' => 'evt_Pi0001', 'type' => 'payment_intent.succeeded', 'account' => 'acct_Pay0001', 'axispay_reduced' => true])
        ->and($payload['data'] ?? null)->toBe(['object' => ['id' => 'pi_FakePaymentIntent', 'object' => 'payment_intent']])
        ->and($stored->payload)->not->toContain('15050')
        ->and(stripeHttp()->requests)->toBe([]);
});

it('accepts direct events signed with the connection secret and syncs with the restricted key', function (): void {
    $secret = GatewayTestHelpers::restrictedKey();
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey($secret)->state(['provider_account_id' => 'acct_Direct0001']));
    $webhookSecret = inTenant($connection, static fn (): string => app(GatewayCredentialsEncrypter::class)->decrypt((string) $connection->provider_webhook_secret, $connection->credentials_key_version));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Direct0001', chargesEnabled: false));
    $event = StripeFixtures::load('account.updated', ['event' => 'evt_Direct0001', 'account' => 'acct_Direct0001', 'livemode' => false]);
    unset($event['account']);

    postWebhook('/webhooks/stripe/direct/'.$connection->id, $event, 'whsec_testconnectsecret')->assertStatus(400);
    postWebhook('/webhooks/stripe/direct/'.$connection->id, $event, $webhookSecret)->assertOk();

    expect(storedEvents())->toHaveCount(1)
        ->and(storedEvents()[0]->status)->toBe(ProviderEventStatus::Processed)
        ->and(storedEvents()[0]->provider_account_id)->toBe('acct_Direct0001')
        ->and(stripeHttp()->requestsTo('get', '/v1/account')[0]['headers']['authorization'])->toBe('Bearer '.$secret)
        ->and(stripeHttp()->requestsTo('get', '/v1/account')[0]['headers'])->not->toHaveKey('stripe-account')
        // ADR-0055: a test-mode api_key connection charges although Stripe has not activated the account.
        ->and(inTenant($connection, static fn () => $connection->refresh()->status))->toBe(ConnectionStatus::Active)
        ->and(inTenant($connection, static fn () => $connection->refresh()->charges_enabled))->toBeFalse();
});

it('refuses direct events of another account or mode', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state(['provider_account_id' => 'acct_Direct0001']));
    $webhookSecret = inTenant($connection, static fn (): string => app(GatewayCredentialsEncrypter::class)->decrypt((string) $connection->provider_webhook_secret, $connection->credentials_key_version));

    postWebhook('/webhooks/stripe/direct/'.$connection->id, StripeFixtures::load('account.updated', ['event' => 'evt_Foreign0001', 'account' => 'acct_Someone0001', 'livemode' => false]), $webhookSecret)->assertStatus(400);
    postWebhook('/webhooks/stripe/direct/'.$connection->id, StripeFixtures::load('account.updated', ['event' => 'evt_Live0002', 'account' => 'acct_Direct0001', 'livemode' => true]), $webhookSecret)->assertStatus(400);

    expect(storedEvents())->toBe([]);
});

it('answers 404 for unknown, non-api_key or disconnected direct connections', function (): void {
    $connect = GatewayTestHelpers::connection(activeTenant());
    $gone = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->disconnected());
    $event = StripeFixtures::load('account.updated', ['event' => 'evt_Nobody0002', 'account' => 'acct_X', 'livemode' => false]);

    foreach (['01J8Z3Q6T4Y0V8KX2M1N5P7R9A', $connect->id, $gone->id] as $id) {
        postWebhook('/webhooks/stripe/direct/'.$id, $event, 'whsec_any')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    expect(storedEvents())->toBe([]);
});

it('marks a revoked key invalid when a direct event cannot be re-read (case 21)', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state(['provider_account_id' => 'acct_Direct0001']));
    $webhookSecret = inTenant($connection, static fn (): string => app(GatewayCredentialsEncrypter::class)->decrypt((string) $connection->provider_webhook_secret, $connection->credentials_key_version));
    stripeHttp()->error('get', '/v1/account', 401);
    $event = StripeFixtures::load('account.updated', ['event' => 'evt_Revoked0001', 'account' => 'acct_Direct0001', 'livemode' => false]);

    postWebhook('/webhooks/stripe/direct/'.$connection->id, $event, $webhookSecret)->assertOk();

    expect(inTenant($connection, static fn () => $connection->refresh()->status))->toBe(ConnectionStatus::InvalidCredentials)
        ->and(storedEvents()[0]->status)->toBe(ProviderEventStatus::Processed);
});
