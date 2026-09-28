<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Jobs\ProcessProviderEventJob;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\ProviderEvents\Services\ProviderEventInbox;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Puts a stored gateway event back on its way (ADR-0051), used by
 * `axispay:provider-events:sweep` (scheduled) and
 * `axispay:provider-events:retry` (operator). Idempotent: each case only
 * acts on the status it expects, and processing itself applies an event
 * once.
 *
 *  - `failed`: back to `received` and queued again (operator retry only);
 *  - `received` for longer than `redispatch_after_seconds`: its job was
 *    lost; queued again;
 *  - `unroutable` whose connection exists now: replaced by a routed copy in
 *    that tenant and queued (the gateway is re-read, so the reduced payload
 *    of the unroutable row is enough).
 *
 * Every requeue is recorded in the tenant's audit log.
 */
final class RecoverProviderEvent
{
    public const string REQUEUED = 'requeued';

    public const string REROUTED = 'rerouted';

    public const string SKIPPED = 'skipped';

    /** @var array<string, GatewayConnection|null> connection per account, mode and attempt, for one run */
    private array $connections = [];

    public function __construct(
        private readonly ProviderEventInbox $inbox,
        private readonly GatewayConnectionResolver $resolver,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return self::REQUEUED|self::REROUTED|self::SKIPPED
     */
    public function handle(ProviderEvent $event, bool $includeFailed): string
    {
        return match ($event->status) {
            ProviderEventStatus::Failed => $includeFailed ? $this->retryFailed($event) : self::SKIPPED,
            ProviderEventStatus::Received => $this->redispatchStale($event),
            ProviderEventStatus::Unroutable => $this->reroute($event),
            default => self::SKIPPED,
        };
    }

    /**
     * @return self::REQUEUED|self::REROUTED|self::SKIPPED
     */
    private function retryFailed(ProviderEvent $event): string
    {
        if ($event->tenant_id === null) {
            return self::SKIPPED;
        }

        $reset = $this->context->runAsTenant($event->tenant_id, $event->livemode, function () use ($event): bool {
            $reset = DB::transaction(static function () use ($event): bool {
                $locked = ProviderEvent::query()->lockForUpdate()->find($event->id);

                if ($locked === null || $locked->status !== ProviderEventStatus::Failed) {
                    return false;
                }

                $locked->forceFill(['status' => ProviderEventStatus::Received, 'processed_at' => null])->save();

                return true;
            });

            if ($reset) {
                $this->audit->record(AuditAction::ProviderEventRetried, $event, ['type' => $event->type, 'from' => ProviderEventStatus::Failed->value], actor: Actor::system());
            }

            return $reset;
        });

        if (! $reset) {
            return self::SKIPPED;
        }

        ProcessProviderEventJob::dispatch($event->id, $event->tenant_id, $event->livemode);

        return self::REQUEUED;
    }

    /**
     * @return self::REQUEUED|self::REROUTED|self::SKIPPED
     */
    private function redispatchStale(ProviderEvent $event): string
    {
        $after = max(1, config()->integer('axispay.gateways.stripe.provider_events.redispatch_after_seconds'));

        // Its job may still be waiting for a retry (backoff): not lost.
        if ($event->tenant_id === null || $event->received_at->greaterThan(now()->subSeconds($after)) || ProcessProviderEventJob::inFlight($event->id)) {
            return self::SKIPPED;
        }

        $this->context->runAsTenant($event->tenant_id, $event->livemode, fn () => $this->audit->record(
            AuditAction::ProviderEventRetried,
            $event,
            ['type' => $event->type, 'from' => ProviderEventStatus::Received->value],
            actor: Actor::system(),
        ));

        ProcessProviderEventJob::dispatch($event->id, $event->tenant_id, $event->livemode);

        return self::REQUEUED;
    }

    /**
     * @return self::REQUEUED|self::REROUTED|self::SKIPPED
     */
    private function reroute(ProviderEvent $event): string
    {
        $connection = $this->connectionFor($event);

        if ($connection === null) {
            return self::SKIPPED;
        }

        // Listed without its payload (sweeper): read it now, for the routed copy.
        if (! array_key_exists('payload', $event->getAttributes())) {
            $event = $this->inbox->findById($event->id);

            if ($event === null || $event->status !== ProviderEventStatus::Unroutable) {
                return self::SKIPPED;
            }
        }

        $routed = $this->context->runAsTenant($connection->tenant_id, $event->livemode, function () use ($event, $connection): ProviderEvent {
            $routed = DB::transaction(function () use ($event, $connection): ProviderEvent {
                $this->inbox->forgetUnroutable($event);

                $row = new ProviderEvent;
                $row->forceFill([
                    'provider' => $event->provider,
                    'provider_event_id' => $event->provider_event_id,
                    'provider_account_id' => $event->provider_account_id,
                    'livemode' => $event->livemode,
                    'type' => $event->type,
                    'object_id' => $event->object_id,
                    'payment_attempt_id' => $event->payment_attempt_id,
                    'payload' => $event->payload,
                    'payload_reduced' => $event->payload_reduced,
                    'received_at' => $event->received_at,
                    'gateway_connection_id' => $connection->id,
                    'status' => ProviderEventStatus::Received,
                ])->save();

                return $row;
            });

            $this->audit->record(AuditAction::ProviderEventRetried, $routed, ['type' => $routed->type, 'from' => ProviderEventStatus::Unroutable->value], actor: Actor::system());

            return $routed;
        });

        Log::info('An unroutable gateway event was routed to its connection.', ['provider_event_id' => $routed->provider_event_id, 'type' => $routed->type]);

        ProcessProviderEventJob::dispatch($routed->id, $connection->tenant_id, $routed->livemode);

        return self::REROUTED;
    }

    /** Unroutable events come from the Connect endpoint: only a Connect connection takes them. */
    private function connectionFor(ProviderEvent $event): ?GatewayConnection
    {
        if ($event->provider_account_id === null) {
            return null;
        }

        // Many events of one account: its connection is resolved once per run.
        $key = implode('|', [$event->provider->value, $event->provider_account_id, $event->livemode ? '1' : '0', $event->payment_attempt_id ?? '-']);

        if (array_key_exists($key, $this->connections)) {
            return $this->connections[$key];
        }

        return $this->connections[$key] = $this->resolveConnection($event, $event->provider_account_id);
    }

    private function resolveConnection(ProviderEvent $event, string $accountId): ?GatewayConnection
    {
        $connection = ($event->payment_attempt_id !== null
            ? $this->resolver->forPaymentAttempt($event->provider, $event->payment_attempt_id, $accountId, $event->livemode)
            : null) ?? $this->resolver->forProviderAccount($event->provider, $accountId, $event->livemode);

        return $connection !== null && $connection->connection_method->usesConnect() ? $connection : null;
    }
}
