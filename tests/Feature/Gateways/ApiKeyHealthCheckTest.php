<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Actions\CheckApiKeyConnectionHealth;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Jobs\CheckApiKeyConnectionsJob;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePaymentGateway;
use Tests\Support\GatewayTestHelpers;

/**
 * Plan 12.3.3 daily health check and plan 26.2 case 21: a revoked key moves
 * the connection to `invalid_credentials`, which blocks link creation, and
 * e-mails the owners and managers. Jobs only carry the connection ID.
 */
beforeEach(function (): void {
    Notification::fake();
});

function runHealthCheck(GatewayConnection $connection): GatewayConnection
{
    return app(TenantContext::class)->runAsTenant($connection->tenant_id, $connection->livemode, static function () use ($connection): GatewayConnection {
        (new CheckApiKeyConnectionsJob($connection->id))->handle(app(CheckApiKeyConnectionHealth::class));

        return $connection->refresh();
    });
}

it('queues one tenant-aware job per api_key connection with only its ID', function (): void {
    Queue::fake();
    $secret = GatewayTestHelpers::restrictedKey();
    $a = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey($secret));
    $b = GatewayTestHelpers::connection(activeTenant(), livemode: true, state: static fn ($factory) => $factory->apiKey(GatewayTestHelpers::restrictedKey(true), GatewayTestHelpers::publishableKey(true)));
    GatewayTestHelpers::connection(activeTenant());
    GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey(GatewayTestHelpers::restrictedKey(suffix: 'Gone'))->disconnected());

    artisanCommand('axispay:gateways:check-api-keys')->assertSuccessful();

    Queue::assertPushed(CheckApiKeyConnectionsJob::class, 2);
    Queue::assertPushed(CheckApiKeyConnectionsJob::class, static function (CheckApiKeyConnectionsJob $job) use ($a, $b, $secret): bool {
        $expected = $job->connectionId === $a->id ? $a : $b;

        return in_array($job->connectionId, [$a->id, $b->id], true)
            && $job->tenantId() === $expected->tenant_id
            && $job->livemode() === $expected->livemode
            && ! str_contains(serialize($job), $secret)
            && ! str_contains(serialize($job), 'rk_');
    });
});

it('is scheduled daily without overlapping, on one server', function (): void {
    $event = collect(app(Schedule::class)->events())->firstOrFail(static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:gateways:check-api-keys'));

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->expression)->toBe('0 6 * * *');
});

it('marks a revoked key invalid, audits and notifies once (case 21)', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state(['provider_account_id' => 'acct_Health0001']));
    $owner = tenantUser($connection->tenant()->firstOrFail());
    FakePaymentGateway::install()->failingWith('acct_Health0001', new GatewayAuthenticationException('revoked', httpStatus: 401));

    $checked = runHealthCheck($connection);
    runHealthCheck($connection);

    expect($checked->status)->toBe(ConnectionStatus::InvalidCredentials)
        ->and($checked->status->canCharge())->toBeFalse()
        ->and($checked->last_health_check_status)->toBe(HealthCheckStatus::AuthenticationFailed)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::GatewayCredentialsInvalid->value)->count())->toBe(1);

    Notification::assertSentToTimes($owner, GatewayConnectionNotification::class, 1);
    Notification::assertSentTo($owner, GatewayConnectionNotification::class, static fn (GatewayConnectionNotification $n): bool => $n->notice === ConnectionNotice::InvalidCredentials);
});

it('records an outage without changing the status', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state(['provider_account_id' => 'acct_Health0002']));
    FakePaymentGateway::install()->failingWith('acct_Health0002', new GatewayUnavailableException('down'));

    $checked = runHealthCheck($connection);

    expect($checked->status)->toBe(ConnectionStatus::Active)
        ->and($checked->last_health_check_status)->toBe(HealthCheckStatus::Unavailable);
});

it('syncs the account and heals invalid credentials once the key works again', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->apiKey()->state([
        'provider_account_id' => 'acct_Health0003',
        'status' => ConnectionStatus::InvalidCredentials,
    ]));
    FakePaymentGateway::install()->withAccount('acct_Health0003', chargesEnabled: true, country: 'MX');

    $checked = runHealthCheck($connection);

    expect($checked->status)->toBe(ConnectionStatus::Active)
        ->and($checked->last_health_check_status)->toBe(HealthCheckStatus::Ok)
        ->and($checked->last_health_check_at)->not->toBeNull();
});
