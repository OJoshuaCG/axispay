<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Actions\DisconnectGatewayConnection;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Events\GatewayConnectionDisconnected;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Jobs\CancelLinksOfDisconnectedGatewayJob;
use App\Modules\PaymentLinks\Listeners\CancelLinksOfDisconnectedGateway;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\startSession;

/**
 * Plan 12.3.4: disconnecting the gateway cancels the active links of that
 * tenant and mode (reason `gateway_disconnected`), and blocks new ones.
 */
it('cancels the active links of the disconnected mode only', function (): void {
    startSession();
    Notification::fake();
    $tenant = ApiTestHelpers::readyTenant();
    GatewayTestHelpers::connection($tenant, livemode: true);

    $active = ApiTestHelpers::link($tenant);
    $paid = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->paid());
    $processing = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->processing());
    $liveActive = ApiTestHelpers::link($tenant, livemode: true);
    $otherTenant = ApiTestHelpers::link(ApiTestHelpers::readyTenant());

    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $connection = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): GatewayConnection => GatewayConnection::query()->current()->firstOrFail());

    app(DisconnectGatewayConnection::class)->handle($owner, $connection);

    $canceled = ApiTestHelpers::freshLink($active->id);
    expect($canceled->status)->toBe(PaymentLinkStatus::Canceled)
        ->and($canceled->cancel_reason)->toBe(CancelReason::GatewayDisconnected->value)
        ->and(ApiTestHelpers::freshLink($paid->id)->status)->toBe(PaymentLinkStatus::Paid)
        ->and(ApiTestHelpers::freshLink($processing->id)->status)->toBe(PaymentLinkStatus::Processing)
        ->and(ApiTestHelpers::freshLink($liveActive->id)->status)->toBe(PaymentLinkStatus::Active)
        ->and(ApiTestHelpers::freshLink($otherTenant->id)->status)->toBe(PaymentLinkStatus::Active);

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentLinkCanceled->value)->sole();
    expect($audit->actor_type)->toBe(ActorType::System)->and($audit->subject_id)->toBe($active->id);

    [, $key] = ApiTestHelpers::key($tenant);
    \Pest\Laravel\postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'after-disconnect'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'gateway_not_ready');
});

/** Marks the tenant's connection in that mode disconnected, as the action would. */
function disconnectConnection(Tenant $tenant, bool $livemode = false): void
{
    app(TenantContext::class)->runAsTenant($tenant->id, $livemode, static function (): void {
        GatewayConnection::query()->current()->firstOrFail()->forceFill(['status' => ConnectionStatus::Disconnected, 'disconnected_at' => now()])->save();
    });
}

function runCancelJob(Tenant $tenant, bool $livemode = false): void
{
    app(TenantContext::class)->runAsTenant($tenant->id, $livemode, static function (): void {
        app()->call([new CancelLinksOfDisconnectedGatewayJob, 'handle']);
    });
}

it('cancels every active link when there are more than one chunk of them', function (): void {
    config(['axispay.links.disconnect_cancel_chunk_size' => 2]);
    $tenant = ApiTestHelpers::readyTenant();
    $links = [];

    foreach (range(1, 7) as $i) {
        $links[] = ApiTestHelpers::link($tenant);
    }

    disconnectConnection($tenant);
    app(CancelLinksOfDisconnectedGateway::class)->handle(new GatewayConnectionDisconnected('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', $tenant->id, false));

    foreach ($links as $link) {
        expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Canceled);
    }
});

it('is queued after commit, tenant-aware and retried', function (): void {
    Queue::fake();
    $tenant = ApiTestHelpers::readyTenant();

    app(CancelLinksOfDisconnectedGateway::class)->handle(new GatewayConnectionDisconnected('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', $tenant->id, true));

    Queue::assertPushed(CancelLinksOfDisconnectedGatewayJob::class, static fn (CancelLinksOfDisconnectedGatewayJob $job): bool => $job->tenantId() === $tenant->id
        && $job->livemode() === true
        && $job->afterCommit === true
        && $job->tries === 5
        && $job->backoff() === [30, 120, 600, 1800]);
});

it('finishes the remaining links when retried after failing halfway', function (): void {
    config(['axispay.links.disconnect_cancel_chunk_size' => 2]);
    $tenant = ApiTestHelpers::readyTenant();
    $links = [];

    foreach (range(1, 5) as $i) {
        $links[] = ApiTestHelpers::link($tenant);
    }

    disconnectConnection($tenant);
    $failOn = $links[2]->id;
    $fail = true;
    PaymentLink::updating(static function (PaymentLink $link) use ($failOn, &$fail): void {
        if ($fail && $link->id === $failOn) {
            $fail = false;

            throw new RuntimeException('Deadlock found when trying to get lock');
        }
    });

    expect(fn () => runCancelJob($tenant))->toThrow(RuntimeException::class);
    expect(ApiTestHelpers::freshLink($links[4]->id)->status)->toBe(PaymentLinkStatus::Active);

    runCancelJob($tenant);

    foreach ($links as $link) {
        expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Canceled);
    }
});

it('does nothing when the tenant reconnected before the job ran', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);

    runCancelJob($tenant);

    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Active);
});

it('reconciles leftovers: links of modes without a connection are canceled, restricted ones kept', function (): void {
    $gone = ApiTestHelpers::readyTenant();
    $leftover = ApiTestHelpers::link($gone);
    disconnectConnection($gone);

    $restricted = Tenant::factory()->status(TenantStatus::Active)->create();
    GatewayTestHelpers::connection($restricted, state: static fn ($f) => $f->state(['status' => ConnectionStatus::Restricted, 'charges_enabled' => false]));
    $kept = ApiTestHelpers::link($restricted);

    $healthy = ApiTestHelpers::link(ApiTestHelpers::readyTenant());

    artisanCommand('axispay:payment-links:reconcile-gateways')
        ->expectsOutputToContain('1 tenant mode(s)')
        ->assertSuccessful();

    expect(ApiTestHelpers::freshLink($leftover->id)->status)->toBe(PaymentLinkStatus::Canceled)
        ->and(ApiTestHelpers::freshLink($leftover->id)->cancel_reason)->toBe('gateway_disconnected')
        ->and(ApiTestHelpers::freshLink($kept->id)->status)->toBe(PaymentLinkStatus::Active)
        ->and(ApiTestHelpers::freshLink($healthy->id)->status)->toBe(PaymentLinkStatus::Active);
});

it('schedules the reconciliation without overlap on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:payment-links:reconcile-gateways'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/15 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('e-mails the owners and queues the cancellation', function (): void {
    startSession();
    Notification::fake();
    Queue::fake();
    $tenant = ApiTestHelpers::readyTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $connection = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): GatewayConnection => GatewayConnection::query()->current()->firstOrFail());

    app(DisconnectGatewayConnection::class)->handle($owner, $connection);

    Notification::assertSentTo($owner, GatewayConnectionNotification::class);
    Queue::assertPushed(CancelLinksOfDisconnectedGatewayJob::class);
    // Owners come first: see "still e-mails the owners when queueing the cancellation fails".
});

it('still e-mails the owners when queueing the cancellation fails', function (): void {
    startSession();
    Notification::fake();
    $tenant = ApiTestHelpers::readyTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    Events::listen(GatewayConnectionDisconnected::class, static function (): void {
        throw new RuntimeException('queue unavailable');
    });
    $connection = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): GatewayConnection => GatewayConnection::query()->current()->firstOrFail());

    expect(fn () => app(DisconnectGatewayConnection::class)->handle($owner, $connection))->toThrow(RuntimeException::class);

    Notification::assertSentTo($owner, GatewayConnectionNotification::class);
});
