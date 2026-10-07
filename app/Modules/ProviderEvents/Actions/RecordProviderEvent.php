<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Actions;

use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Jobs\ProcessProviderEventJob;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\ProviderEvents\Services\ProviderEventInbox;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14.2 steps 3 and 4: stores a verified event once and queues its
 * processing. The unique `provider_event_id` turns a duplicate delivery into
 * a no-op (plan 26.2 case 3). An event whose account matches no connection
 * is stored as `unroutable` (platform row) and logged at alert level (plan
 * 6.3); the superadmin e-mail for it arrives with the platform alerts
 * (Phase 9).
 *
 * Plan 14.4: an event of a kind the platform does not handle is filtered
 * here, cheaply: stored directly as `ignored`, without a job, and only with
 * its reduced payload (no object data, so no payer data of sales the
 * platform never made). Unroutable events keep the reduced payload too.
 * Old rows are purged by `axispay:provider-events:purge`.
 *
 * Plan 14.4 (Phase 4): a payment event about a payment the platform did not
 * create (no attempt ID in its metadata, frequent on api_key and oauth
 * accounts, which also carry the merchant's other sales) is stored as
 * `ignored` with reason `foreign_object` and the reduced payload, before any
 * call to the gateway. A refund or dispute event (ADR-0066) carries no
 * metadata of ours: its payment is found by the gateway's ID among the
 * tenant's attempts, and a payment the platform holds no attempt for is a
 * foreign object too.
 */
final readonly class RecordProviderEvent
{
    public function __construct(
        private TenantContext $context,
        private ProviderEventInbox $inbox,
    ) {}

    /**
     * @return bool true when the event is new (false: duplicate, not reprocessed)
     */
    public function handle(GatewayProvider $provider, ProviderWebhookEvent $event, ?GatewayConnection $connection): bool
    {
        $ownAttemptId = $this->ownAttemptOf($provider, $event, $connection);
        $attributes = [
            'provider' => $provider,
            'provider_event_id' => $event->providerEventId,
            'provider_account_id' => $event->providerAccountId,
            'livemode' => $event->livemode,
            'type' => $event->type,
            'object_id' => $event->objectId,
            'payment_attempt_id' => $event->attemptReference !== null && Ulid::isValid($event->attemptReference) ? $event->attemptReference : $ownAttemptId,
            'payload' => $event->storedPayload(routed: $connection !== null),
            'payload_reduced' => ! $event->keepsFullPayload(routed: $connection !== null),
            'received_at' => now(),
        ];
        $foreign = $event->isForeignPayment() || ($event->isAboutPaymentById() && $connection !== null && $ownAttemptId === null);
        $handled = $event->kind !== ProviderEventKind::Unhandled && ! $foreign;

        try {
            if ($connection === null) {
                $this->storeUnroutable($attributes);

                return true;
            }

            $stored = $this->context->runAsTenant($connection->tenant_id, $event->livemode, static function () use ($attributes, $connection, $handled, $foreign): ProviderEvent {
                $row = new ProviderEvent;
                $row->forceFill([
                    ...$attributes,
                    'gateway_connection_id' => $connection->id,
                    'status' => $handled ? ProviderEventStatus::Received : ProviderEventStatus::Ignored,
                    'processed_at' => $handled ? null : now(),
                    'last_error' => $foreign ? ProviderEventStatus::FOREIGN_OBJECT : null,
                ])->save();

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate($provider, $event, $connection);
        }

        if ($handled) {
            ProcessProviderEventJob::dispatch($stored->id, $connection->tenant_id, $event->livemode);
        }

        return true;
    }

    /**
     * Our attempt for the payment a refund or dispute event is about, read in
     * the connection's tenant and mode; null when the platform has none.
     */
    private function ownAttemptOf(GatewayProvider $provider, ProviderWebhookEvent $event, ?GatewayConnection $connection): ?string
    {
        if ($connection === null || ! $event->isAboutPaymentById() || $event->providerPaymentId === null) {
            return null;
        }

        $paymentId = $event->providerPaymentId;
        $attemptId = $this->context->runAsTenant($connection->tenant_id, $event->livemode, static fn (): mixed => PaymentAttempt::query()
            ->where('provider', $provider->value)
            ->where('provider_payment_id', $paymentId)
            ->value('id'));

        return is_string($attemptId) ? $attemptId : null;
    }

    /**
     * A delivery of an event already stored (ADR-0051):
     *
     *  - stored as unroutable and routable now (the same event reached the
     *    endpoint of its connection, or the connection exists now): the
     *    routed copy replaces it;
     *  - still `received` for longer than `redispatch_after_seconds` (its job
     *    was lost, e.g. the dispatch failed after the insert): queued again;
     *  - anything else: a plain duplicate, not reprocessed (plan 26.2 case 3).
     */
    private function duplicate(GatewayProvider $provider, ProviderWebhookEvent $event, ?GatewayConnection $connection): bool
    {
        $existing = $this->inbox->find($provider, $event->providerEventId);

        if ($existing === null) {
            return false;
        }

        if ($existing->status === ProviderEventStatus::Unroutable && $connection !== null) {
            $this->inbox->forgetUnroutable($existing);

            return $this->handle($provider, $event, $connection);
        }

        $after = max(1, config()->integer('axispay.gateways.stripe.provider_events.redispatch_after_seconds'));

        if ($existing->status === ProviderEventStatus::Received && $existing->tenant_id !== null && $existing->received_at->lessThanOrEqualTo(now()->subSeconds($after))) {
            ProcessProviderEventJob::dispatch($existing->id, $existing->tenant_id, $existing->livemode);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeUnroutable(array $attributes): void
    {
        $row = new ProviderEvent;
        $row->forceFill([...$attributes, 'tenant_id' => null, 'status' => ProviderEventStatus::Unroutable])->save();

        Log::alert('Unroutable gateway event: no connection matches its account.', [
            'provider_event_id' => $row->provider_event_id,
            'provider_account_id' => $row->provider_account_id,
            'type' => $row->type,
            'livemode' => $row->livemode,
        ]);
    }
}
