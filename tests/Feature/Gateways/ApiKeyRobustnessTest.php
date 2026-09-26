<?php

declare(strict_types=1);

use App\Modules\Gateways\Actions\CheckApiKeyConnectionHealth;
use App\Modules\Gateways\Actions\ConnectWithApiKey;
use App\Modules\Gateways\Actions\UpdateApiKeyCredentials;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePaymentGateway;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeApiKeyScenario;

use function Pest\Laravel\startSession;

/**
 * Security review findings M2-M4 and M1(c) (ADR-0047).
 */
beforeEach(function (): void {
    startSession();
    Notification::fake();
});

function robustOwner(): User
{
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::reauthenticated();

    return $owner;
}

function keyPair(string $suffix = 'A1b2'): ApiKeyConnectionData
{
    return new ApiKeyConnectionData(ApiKeyCredentials::from(GatewayTestHelpers::restrictedKey(suffix: $suffix), GatewayTestHelpers::publishableKey()), riskAcknowledged: true);
}

// M2 ------------------------------------------------------------------------

it('does not mark new keys invalid when an old key fails after a rotation', function (): void {
    $owner = robustOwner();
    $connection = GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->apiKey(GatewayTestHelpers::restrictedKey(suffix: 'Old1'))->state(['provider_account_id' => 'acct_Race0001']));
    FakePaymentGateway::install()->failingWith('acct_Race0001', new GatewayAuthenticationException('revoked', httpStatus: 401));

    // The health check loaded the connection with the OLD key; the keys are
    // rotated while its call to Stripe is in flight.
    $stale = GatewayConnection::query()->findOrFail($connection->id);
    GatewayConnection::query()->findOrFail($connection->id)->forceFill(['credentials_fingerprint' => hash('sha256', GatewayTestHelpers::restrictedKey(suffix: 'New2'))])->save();

    expect(app(CheckApiKeyConnectionHealth::class)->handle($stale))->toBe(HealthCheckStatus::AuthenticationFailed)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active);
    Notification::assertNothingSent();

    // Without a rotation the same failure does block the connection.
    app(CheckApiKeyConnectionHealth::class)->handle($connection->refresh());
    expect($connection->refresh()->status)->toBe(ConnectionStatus::InvalidCredentials);
});

// M3 ------------------------------------------------------------------------

it('removes the remote endpoint and the row when activation fails after the endpoint exists', function (): void {
    $owner = robustOwner();
    (new StripeApiKeyScenario(stripeHttp()))->install();
    GatewayConnection::updating(static fn () => throw new RuntimeException('database failure while activating'));

    expect(fn () => app(ConnectWithApiKey::class)->handle($owner, keyPair()))->toThrow(RuntimeException::class, 'database failure');

    $created = stripeHttp()->requestsTo('post', '/v1/webhook_endpoints');
    $deleted = array_values(array_filter(stripeHttp()->requests, static fn (array $r): bool => $r['method'] === 'delete'));

    expect($created)->toHaveCount(1)
        ->and($deleted)->toHaveCount(1)
        ->and(GatewayConnection::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('deletes the new endpoint and refuses cleanly when the key update loses a fingerprint race', function (): void {
    $owner = robustOwner();
    (new StripeApiKeyScenario(stripeHttp()))->install();
    $connection = app(ConnectWithApiKey::class)->handle($owner, keyPair());
    $oldEndpoint = $connection->provider_webhook_endpoint_id;

    GatewayConnection::updating(static fn () => throw new UniqueConstraintViolationException('mariadb', 'update gateway_connections', [], new PDOException('Duplicate entry for uq_gateway_connections_fingerprint')));

    $e = thrownBy(ApiKeyValidationException::class, fn () => app(UpdateApiKeyCredentials::class)->handle($owner, $connection, keyPair('Dup3')));
    $newEndpoint = stripeHttp()->requestsTo('post', '/v1/webhook_endpoints')[1]['headers']['idempotency-key'] ?? '';
    $deletes = array_map(static fn (array $r): string => $r['path'], array_values(array_filter(stripeHttp()->requests, static fn (array $r): bool => $r['method'] === 'delete')));

    expect($e->rejection)->toBe(ApiKeyRejection::KeyAlreadyLinked)
        ->and($deletes)->toBe(['/v1/webhook_endpoints/we_'.substr(hash('sha256', $newEndpoint), 0, 16)])
        ->and($connection->refresh()->provider_webhook_endpoint_id)->toBe($oldEndpoint)
        ->and($connection->maskedSecret())->toBe('rk_test_…A1b2');
});

// M4 ------------------------------------------------------------------------

it('uses a fresh idempotency key for every key update, even with the same key', function (): void {
    $owner = robustOwner();
    (new StripeApiKeyScenario(stripeHttp()))->install();
    $connection = app(ConnectWithApiKey::class)->handle($owner, keyPair());

    $first = app(UpdateApiKeyCredentials::class)->handle($owner, $connection, keyPair('Same'));
    $firstEndpoint = $first->provider_webhook_endpoint_id;
    $second = app(UpdateApiKeyCredentials::class)->handle($owner, $first, keyPair('Same'));

    $keys = array_map(static fn (array $r): string => (string) ($r['headers']['idempotency-key'] ?? ''), stripeHttp()->requestsTo('post', '/v1/webhook_endpoints'));

    expect($keys)->toHaveCount(3)
        ->and(array_unique($keys))->toHaveCount(3)
        ->and($keys[2])->toMatch('/^axispay-webhook-endpoint-'.$connection->id.'-[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($keys[2])->not->toContain(substr(hash('sha256', GatewayTestHelpers::restrictedKey(suffix: 'Same')), 0, 16))
        ->and($second->provider_webhook_endpoint_id)->not->toBe($firstEndpoint);
});

// M1(c) ---------------------------------------------------------------------

it('updates the events of existing direct endpoints to the configured list', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state(['provider_webhook_endpoint_id' => 'we_Existing0001']));
    GatewayTestHelpers::connection(activeTenant());
    stripeHttp()->on('post', '/v1/webhook_endpoints/we_Existing0001', ['id' => 'we_Existing0001', 'object' => 'webhook_endpoint', 'enabled_events' => ['account.updated', 'payment_intent.succeeded']]);
    config(['axispay.gateways.stripe.direct_webhook_events' => ['account.updated', 'payment_intent.succeeded']]);

    artisanCommand('axispay:stripe-sync-webhook-endpoints')->assertSuccessful();

    $update = stripeHttp()->requestsTo('post', '/v1/webhook_endpoints/we_Existing0001');

    expect($update)->toHaveCount(1)
        ->and($update[0]['params'])->toBe(['enabled_events' => ['account.updated', 'payment_intent.succeeded']])
        ->and($update[0]['headers']['idempotency-key'])->toStartWith('axispay-webhook-sync-'.$connection->id.'-')
        ->and($update[0]['headers'])->not->toHaveKey('stripe-account');
});

it('marks a connection invalid when the endpoint sync is rejected', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state(['provider_webhook_endpoint_id' => 'we_Revoked0001']));
    stripeHttp()->error('post', '/v1/webhook_endpoints/we_Revoked0001', 401);

    artisanCommand('axispay:stripe-sync-webhook-endpoints')->assertFailed();

    expect(GatewayConnection::query()->withoutGlobalScopes()->findOrFail($connection->id)->status)->toBe(ConnectionStatus::InvalidCredentials);
});
