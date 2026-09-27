<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Actions;

use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Jobs\ProcessProviderEventJob;
use App\Modules\ProviderEvents\Models\ProviderEvent;
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
 * call to the gateway.
 */
final readonly class RecordProviderEvent
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return bool true when the event is new (false: duplicate, not reprocessed)
     */
    public function handle(GatewayProvider $provider, ProviderWebhookEvent $event, ?GatewayConnection $connection): bool
    {
        $attributes = [
            'provider' => $provider,
            'provider_event_id' => $event->providerEventId,
            'provider_account_id' => $event->providerAccountId,
            'livemode' => $event->livemode,
            'type' => $event->type,
            'object_id' => $event->objectId,
            'payment_attempt_id' => $event->attemptReference !== null && Ulid::isValid($event->attemptReference) ? $event->attemptReference : null,
            'payload' => $event->storedPayload(routed: $connection !== null),
            'received_at' => now(),
        ];
        $foreign = $event->isForeignPayment();
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
            return false;
        }

        if ($handled) {
            ProcessProviderEventJob::dispatch($stored->id, $connection->tenant_id, $event->livemode);
        }

        return true;
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
