<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Actions;

use App\Modules\Gateways\Actions\DisconnectGatewayConnection;
use App\Modules\Gateways\Actions\SyncGatewayConnection;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Shared\Logging\Redactor;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one stored gateway event (plan 14.2 steps 5-8, 14.3). Runs in the
 * tenant context restored by the job. Idempotent: only `received` events are
 * processed, so a duplicate or a retried job never applies twice (plan 26.2
 * case 3).
 *
 * The handler never trusts the payload: it re-reads the object from the
 * gateway (ADR-017) and applies the change through the Gateways actions.
 *
 *  - account updated: SyncGatewayConnection (re-fetch, status transition);
 *  - account deauthorized (Connect): confirmed by re-reading the account;
 *    only when the gateway now denies access is the connection disconnected;
 *  - payment updated (Phase 4): the attempt named by the payment's
 *    metadata is re-read from the gateway and applied (SyncPaymentAttempt);
 *    an authorized payment is completed (a payer who closed the tab after 3D
 *    Secure, ADR-0050). No attempt → `ignored`, `foreign_object` (14.4);
 *  - anything else: `ignored` (refunds and disputes arrive in Phase 7).
 */
final readonly class ProcessProviderEvent
{
    public function __construct(
        private GatewayFactory $gateways,
        private SyncGatewayConnection $sync,
        private DisconnectGatewayConnection $disconnect,
        private TenantContext $context,
        private SyncPaymentAttempt $syncPayment,
    ) {}

    public function handle(string $providerEventId): void
    {
        $event = ProviderEvent::query()->find($providerEventId);

        if ($event === null || $event->status !== ProviderEventStatus::Received) {
            return;
        }

        $connection = $event->gateway_connection_id !== null
            ? GatewayConnection::query()->find($event->gateway_connection_id)
            : null;

        try {
            [$outcome, $reason] = $connection === null ? [ProviderEventStatus::Ignored, null] : $this->apply($event, $connection);
        } catch (Throwable $e) {
            $this->recordAttempt($event, $e);

            throw $e;
        }

        $this->finish($event, $outcome, $reason);
    }

    public function markFailed(string $providerEventId, string $tenantId, bool $livemode, ?Throwable $exception): void
    {
        $this->context->runAsTenant($tenantId, $livemode, function () use ($providerEventId, $exception): void {
            $event = ProviderEvent::query()->find($providerEventId);

            if ($event === null || $event->status !== ProviderEventStatus::Received) {
                return;
            }

            $event->forceFill(['status' => ProviderEventStatus::Failed, 'last_error' => self::describe($exception)])->save();

            Log::error('A gateway event failed after all retries.', [
                'provider_event_id' => $event->provider_event_id,
                'type' => $event->type,
                'exception' => $exception !== null ? $exception::class : null,
            ]);
        });
    }

    /**
     * @return array{0: ProviderEventStatus, 1: string|null} outcome and the reason of an `ignored`
     */
    private function apply(ProviderEvent $event, GatewayConnection $connection): array
    {
        return match ($this->kindOf($event, $connection)) {
            ProviderEventKind::AccountUpdated => [$this->accountUpdated($connection), null],
            ProviderEventKind::AccountDeauthorized => [$this->accountDeauthorized($connection), null],
            ProviderEventKind::PaymentUpdated => $this->paymentUpdated($event),
            ProviderEventKind::Unhandled => [ProviderEventStatus::Ignored, null],
        };
    }

    /**
     * @return array{0: ProviderEventStatus, 1: string|null}
     */
    private function paymentUpdated(ProviderEvent $event): array
    {
        $attempt = $event->payment_attempt_id !== null ? PaymentAttempt::query()->find($event->payment_attempt_id) : null;

        if ($attempt === null || $event->object_id === null) {
            return [ProviderEventStatus::Ignored, ProviderEventStatus::FOREIGN_OBJECT];
        }

        $this->syncPayment->handle($attempt->id, SyncReason::Webhook, providerPaymentId: $event->object_id);

        return [ProviderEventStatus::Processed, null];
    }

    private function accountUpdated(GatewayConnection $connection): ProviderEventStatus
    {
        if ($connection->status->isDisconnected()) {
            return ProviderEventStatus::Ignored;
        }

        // For api_key, an authentication error marks `invalid_credentials`
        // inside the sync; for Connect it propagates and the job retries.
        $this->sync->handle($connection);

        return ProviderEventStatus::Processed;
    }

    private function accountDeauthorized(GatewayConnection $connection): ProviderEventStatus
    {
        if ($connection->status->isDisconnected() || ! $connection->connection_method->usesConnect()) {
            return ProviderEventStatus::Ignored;
        }

        try {
            $this->gateways->for($connection->provider)->retrieveAccount($connection);
        } catch (GatewayAuthenticationException) {
            $this->disconnect->handleDeauthorized($connection);

            return ProviderEventStatus::Processed;
        }

        // The platform can still read the account: a stale or replayed event.
        return ProviderEventStatus::Ignored;
    }

    /**
     * The kind comes from the adapter's mapping of the stored provider type,
     * so this pipeline never matches gateway event names itself.
     */
    private function kindOf(ProviderEvent $event, GatewayConnection $connection): ProviderEventKind
    {
        return $this->gateways->for($event->provider)->eventKind($event->type, direct: ! $connection->connection_method->usesConnect());
    }

    private function finish(ProviderEvent $event, ProviderEventStatus $outcome, ?string $reason): void
    {
        // Plan 14.4: a foreign object keeps only the reduced payload.
        $reduced = $reason === ProviderEventStatus::FOREIGN_OBJECT ? $this->gateways->for($event->provider)->reduceWebhookPayload($event->payload) : null;

        DB::transaction(static function () use ($event, $outcome, $reason, $reduced): void {
            $locked = ProviderEvent::query()->lockForUpdate()->find($event->id);

            if ($locked === null || $locked->status !== ProviderEventStatus::Received) {
                return;
            }

            $locked->forceFill([
                'status' => $outcome,
                'attempts' => $locked->attempts + 1,
                'processed_at' => now(),
                'last_error' => $reason,
                ...($reduced !== null ? ['payload' => $reduced, 'payload_reduced' => true] : []),
            ])->save();
        });
    }

    private function recordAttempt(ProviderEvent $event, Throwable $e): void
    {
        $event->forceFill(['attempts' => $event->attempts + 1, 'last_error' => self::describe($e)])->save();
    }

    /** Class name and our own message only: gateway messages are never stored. */
    private static function describe(?Throwable $e): ?string
    {
        return $e === null ? null : mb_substr(app(Redactor::class)->redactString($e::class.': '.$e->getMessage()), 0, 1000);
    }
}
