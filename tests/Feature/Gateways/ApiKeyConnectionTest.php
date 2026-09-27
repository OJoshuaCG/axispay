<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Actions\ConnectWithApiKey;
use App\Modules\Gateways\Actions\DisconnectGatewayConnection;
use App\Modules\Gateways\Actions\StartPlatformOnboarding;
use App\Modules\Gateways\Actions\UpdateApiKeyCredentials;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\DisconnectReason;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeApiKeyScenario;
use Tests\Support\StripeFixtures;

use function Pest\Laravel\startSession;

/**
 * Plan 12.3.3 (2C, brought forward to Phase 2 by ADR-0047) and plan 26.2
 * case 18: every check runs before anything is stored, a refused key leaves
 * no trace but an audit entry with the reason, and a valid key is stored
 * encrypted with a webhook endpoint created on the merchant account.
 */
beforeEach(function (): void {
    startSession();
    Notification::fake();
});

function apiKeyOwner(bool $livemode = false, TenantStatus $status = TenantStatus::PendingOnboarding): User
{
    $owner = actingAsTenantUser(tenantUser(Tenant::factory()->status($status)->create()), $livemode);
    GatewayTestHelpers::reauthenticated();

    return $owner;
}

function apiKeyScenario(): StripeApiKeyScenario
{
    return (new StripeApiKeyScenario(stripeHttp()))->install();
}

function apiKeyData(?string $secret = null, ?string $publishable = null, bool $livemode = false, bool $accept = true, bool $excessive = false): ApiKeyConnectionData
{
    return new ApiKeyConnectionData(
        ApiKeyCredentials::from($secret ?? GatewayTestHelpers::restrictedKey($livemode), $publishable ?? GatewayTestHelpers::publishableKey($livemode)),
        riskAcknowledged: $accept,
        acceptExcessivePermissions: $excessive,
    );
}

function expectRejection(Closure $call, ApiKeyRejection $rejection): void
{
    expect(thrownBy(ApiKeyValidationException::class, $call)->rejection)->toBe($rejection)
        ->and(GatewayConnection::query()->count())->toBe(0);
}

it('connects with a valid restricted key: encrypted, masked, webhook created, active', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    $secret = GatewayTestHelpers::restrictedKey();

    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData($secret));

    $raw = GatewayConnection::query()->findOrFail($connection->id)->getRawOriginal();
    $endpoint = stripeHttp()->requestsTo('post', '/v1/webhook_endpoints')[0];

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->connection_method)->toBe(ConnectionMethod::ApiKey)
        ->and($connection->provider_account_id)->toBe('acct_Merchant0001')
        ->and($connection->country)->toBe('MX')
        ->and($connection->maskedSecret())->toBe('rk_test_…A1b2')
        ->and($connection->credentials_fingerprint)->toBe(hash('sha256', $secret))
        ->and($connection->credentials_publishable)->toBe(GatewayTestHelpers::publishableKey())
        ->and($connection->risk_acknowledged_by_user_id)->toBe($owner->id)
        ->and($connection->risk_acknowledged_at)->not->toBeNull()
        ->and($connection->provider_webhook_endpoint_id)->toStartWith('we_')
        ->and(json_encode($raw))->not->toContain($secret)
        ->and(json_encode($raw))->not->toContain('whsec_FakeEndpointSecret0001')
        ->and(app(GatewayCredentialsEncrypter::class)->decrypt((string) $connection->credentials_secret, $connection->credentials_key_version))->toBe($secret)
        ->and($endpoint['params']['url'])->toBe('https://api.localhost/webhooks/stripe/direct/'.$connection->id)
        ->and($endpoint['params']['api_version'])->toBe('2026-08-26.dahlia')
        // Only the events the platform handles (ADR-0047, ADR-0051): account and payment events.
        ->and($endpoint['params']['enabled_events'])->toBe(['account.updated', 'payment_intent.amount_capturable_updated', 'payment_intent.canceled', 'payment_intent.payment_failed', 'payment_intent.processing', 'payment_intent.requires_action', 'payment_intent.succeeded'])
        ->and($endpoint['headers']['idempotency-key'])->toMatch('/^axispay-webhook-endpoint-'.$connection->id.'-[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($endpoint['headers'])->not->toHaveKey('stripe-account')
        ->and($connection->validated_permissions['missing'] ?? null)->toBe([])
        ->and($connection->validated_permissions['excessive'] ?? null)->toBe([])
        ->and(tenantOf($owner)->status)->toBe(TenantStatus::Active)
        ->and(AuditLog::query()->where('action', AuditAction::GatewayRiskAcknowledged->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::GatewayConnected->value)->count())->toBe(1);

    Notification::assertSentTo($owner, GatewayConnectionNotification::class, static fn (GatewayConnectionNotification $n): bool => $n->notice === ConnectionNotice::Connected);
});

it('verifies that the publishable key belongs to the same account with a PII token', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();

    app(ConnectWithApiKey::class)->handle($owner, apiKeyData());

    $token = stripeHttp()->requestsTo('post', '/v1/tokens')[0];
    $retrieve = stripeHttp()->requestsTo('get', '/v1/tokens/tok_PiiProbe0001')[0];

    expect($token['headers']['authorization'])->toBe('Bearer '.GatewayTestHelpers::publishableKey())
        ->and($token['params'])->toHaveKey('pii')
        ->and($token['headers']['idempotency-key'])->toStartWith('axispay-pk-check-')
        ->and($retrieve['headers']['authorization'])->toBe('Bearer '.GatewayTestHelpers::restrictedKey());
});

it('rejects secret keys without calling Stripe (case 18)', function (string $secret): void {
    $owner = apiKeyOwner();

    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData($secret)), ApiKeyRejection::SecretKeyNotAllowed);
    expect(stripeHttp()->requests)->toBe([])
        ->and(AuditLog::query()->where('action', AuditAction::GatewayCredentialsRejected->value)->count())->toBe(1)
        ->and(json_encode(AuditLog::query()->pluck('changes')))->not->toContain($secret);
})->with(['sk_test_51FakeSecretKey000000000000', 'sk_live_51FakeSecretKey000000000000']);

it('rejects keys that are not a restricted + publishable pair', function (string $secret, string $publishable, ApiKeyRejection $rejection): void {
    $owner = apiKeyOwner();

    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData($secret, $publishable)), $rejection);
    expect(stripeHttp()->requests)->toBe([]);
})->with([
    'not rk' => ['pk_test_51FakeKey0000000000000000', 'pk_test_51FakePublishableKey000000000Pk01', ApiKeyRejection::NotARestrictedKey],
    'bad pk' => ['rk_test_51FakeRestrictedKey0000000000A1b2', 'sk_test_51Fake0000000000000', ApiKeyRejection::InvalidPublishableKey],
    'modes differ' => ['rk_test_51FakeRestrictedKey0000000000A1b2', 'pk_live_51FakePublishableKey000000000Pk01', ApiKeyRejection::KeyModesDiffer],
    'live keys in test panel' => ['rk_live_51FakeRestrictedKey0000000000A1b2', 'pk_live_51FakePublishableKey000000000Pk01', ApiKeyRejection::PanelModeMismatch],
]);

it('rejects a publishable key of another account (case 18)', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario()->publishableKeyOfAnotherAccount();

    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData()), ApiKeyRejection::PublishableKeyOtherAccount);
});

it('rejects a restricted key without the required permissions (case 18)', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario()->without('payment_intent_write', 'dispute_read');

    $e = thrownBy(ApiKeyValidationException::class, fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData()));

    expect($e->rejection)->toBe(ApiKeyRejection::MissingPermissions)
        ->and($e->details)->toBe(['payment_intent_write', 'dispute_read'])
        ->and($e->userMessage())->toContain('payment_intent_write');

    expect(GatewayConnection::query()->count())->toBe(0)
        ->and(stripeHttp()->requestsTo('post', '/v1/webhook_endpoints'))->toBe([]);
});

it('probes write permissions with an empty body and an idempotency key', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();

    app(ConnectWithApiKey::class)->handle($owner, apiKeyData());

    foreach (['/v1/payment_intents', '/v1/refunds', '/v1/payouts', '/v1/transfers'] as $path) {
        $probe = stripeHttp()->requestsTo('post', $path)[0];

        expect($probe['params'])->toBe([])
            ->and($probe['headers']['idempotency-key'])->toStartWith('axispay-probe-');
    }
});

it('rejects a revoked or unreadable key and a disallowed country', function (): void {
    $owner = apiKeyOwner();
    stripeHttp()->error('get', '/v1/account', 401);
    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData()), ApiKeyRejection::KeyRejected);

    stripeHttp()->error('get', '/v1/account', 403, 'invalid_request_error');
    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData()), ApiKeyRejection::AccountNotReadable);

    (new StripeApiKeyScenario(stripeHttp()))->country('US')->install();
    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData()), ApiKeyRejection::CountryNotAllowed);
});

it('requires the extra confirmation for excessive permissions in live mode only', function (): void {
    $owner = apiKeyOwner(livemode: true);
    apiKeyScenario()->with('payout_write', 'balance_read');

    $e = thrownBy(ApiKeyValidationException::class, fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData(livemode: true)));

    expect($e->rejection)->toBe(ApiKeyRejection::ExcessivePermissionsNotConfirmed)
        ->and($e->details)->toBe(['payout_write', 'balance_read']);

    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData(livemode: true, excessive: true));

    expect($connection->livemode)->toBeTrue()
        ->and($connection->validated_permissions['excessive'] ?? null)->toBe(['payout_write', 'balance_read']);
    Notification::assertSentTo($owner, GatewayConnectionNotification::class, static fn (GatewayConnectionNotification $n): bool => $n->notice === ConnectionNotice::ExcessivePermissions);
});

it('warns without blocking about excessive permissions in test mode', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario()->with('transfer_write');

    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData());

    expect($connection->validated_permissions['excessive'] ?? null)->toBe(['transfer_write']);
});

it('refuses a key or an account already linked to another tenant', function (): void {
    $secret = GatewayTestHelpers::restrictedKey();
    GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey($secret)->state(['provider_account_id' => 'acct_Other0001']));

    $owner = apiKeyOwner();
    apiKeyScenario();
    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData($secret)), ApiKeyRejection::KeyAlreadyLinked);

    GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->disconnected()->state(['provider_account_id' => 'acct_Merchant0001']));
    expectRejection(fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData(GatewayTestHelpers::restrictedKey(suffix: 'Zz99'))), ApiKeyRejection::AccountAlreadyLinked);
});

it('keeps nothing when the webhook endpoint cannot be created', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    stripeHttp()->error('post', '/v1/webhook_endpoints', 400, 'invalid_request_error', 'url_invalid');

    app(ConnectWithApiKey::class)->handle($owner, apiKeyData());
})->throws(ApiKeyValidationException::class);

it('requires the risk notice, re-authentication and the method enabled', function (): void {
    $owner = apiKeyOwner();

    expect(thrownBy(GatewayConnectionException::class, fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData(accept: false)))->error)
        ->toBe(ConnectionError::RiskNotAcknowledged);

    config(['axispay.gateways.stripe.connection_methods.api_key' => false]);
    expect(thrownBy(GatewayConnectionException::class, fn () => app(ConnectWithApiKey::class)->handle($owner, apiKeyData()))->error)
        ->toBe(ConnectionError::MethodDisabled);
});

it('updates the keys of the same account and replaces the webhook endpoint', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData());
    $oldEndpoint = $connection->provider_webhook_endpoint_id;
    $newSecret = GatewayTestHelpers::restrictedKey(suffix: 'N3w4');

    $updated = app(UpdateApiKeyCredentials::class)->handle($owner, $connection, apiKeyData($newSecret));

    expect($updated->id)->toBe($connection->id)
        ->and($updated->maskedSecret())->toBe('rk_test_…N3w4')
        ->and($updated->provider_webhook_endpoint_id)->not->toBe($oldEndpoint)
        ->and(stripeHttp()->requestsTo('delete', '/v1/webhook_endpoints/'.$oldEndpoint))->toHaveCount(1)
        ->and(app(GatewayCredentialsEncrypter::class)->decrypt((string) $updated->credentials_secret, $updated->credentials_key_version))->toBe($newSecret)
        ->and(AuditLog::query()->where('action', AuditAction::GatewayCredentialsUpdated->value)->count())->toBe(1);
});

it('refuses new keys of a different account', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData());

    (new StripeApiKeyScenario(stripeHttp(), accountId: 'acct_Different001'))->install();

    $e = thrownBy(ApiKeyValidationException::class, fn () => app(UpdateApiKeyCredentials::class)->handle($owner, $connection, apiKeyData(GatewayTestHelpers::restrictedKey(suffix: 'D1ff'))));

    expect($e->rejection)->toBe(ApiKeyRejection::DifferentAccount);

    expect($connection->refresh()->maskedSecret())->toBe('rk_test_…A1b2');
});

it('disconnects: deletes the remote endpoint and destroys the stored credentials', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData());
    $endpoint = $connection->provider_webhook_endpoint_id;

    $disconnected = app(DisconnectGatewayConnection::class)->handle($owner, $connection);

    expect($disconnected->status)->toBe(ConnectionStatus::Disconnected)
        ->and($disconnected->disconnect_reason)->toBe(DisconnectReason::UserRequested)
        ->and($disconnected->credentials_secret)->toBeNull()
        ->and($disconnected->credentials_publishable)->toBeNull()
        ->and($disconnected->credentials_fingerprint)->toBeNull()
        ->and($disconnected->provider_webhook_secret)->toBeNull()
        ->and($disconnected->provider_webhook_endpoint_id)->toBeNull()
        ->and(stripeHttp()->requestsTo('delete', '/v1/webhook_endpoints/'.$endpoint))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', AuditAction::GatewayDisconnected->value)->firstOrFail()->changes['remote_webhook_endpoint_deleted'] ?? null)->toBeTrue()
        ->and(GatewayConnection::query()->current()->exists())->toBeFalse();

    Notification::assertSentTo($owner, GatewayConnectionNotification::class, static fn (GatewayConnectionNotification $n): bool => $n->notice === ConnectionNotice::Disconnected);
});

it('lets a tenant move from api_key to platform_onboarding after disconnecting', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    $connection = app(ConnectWithApiKey::class)->handle($owner, apiKeyData());
    app(DisconnectGatewayConnection::class)->handle($owner, $connection);

    stripeHttp()
        ->on('post', '/v1/accounts', StripeFixtures::account('acct_NewPlatform01', chargesEnabled: false))
        ->on('post', '/v1/account_links', ['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/s/next']);

    app(StartPlatformOnboarding::class)->handle($owner, 'MX');

    expect(GatewayConnection::query()->current()->firstOrFail()->connection_method)->toBe(ConnectionMethod::PlatformOnboarding)
        ->and(GatewayConnection::query()->count())->toBe(2);
});

it('refuses a second connection in the same mode', function (): void {
    $owner = apiKeyOwner();
    apiKeyScenario();
    app(ConnectWithApiKey::class)->handle($owner, apiKeyData());

    app(ConnectWithApiKey::class)->handle($owner, apiKeyData(GatewayTestHelpers::restrictedKey(suffix: 'Sec2')));
})->throws(GatewayConnectionException::class);
