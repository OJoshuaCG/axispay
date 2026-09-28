<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Services;

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cross-tenant access to stored gateway events for the recovery paths
 * (ADR-0051; on the scope-bypass whitelist, config/tenancy.php): the row
 * behind a duplicate delivery, events stuck in `received`, unroutable events
 * waiting for their connection and failed events to retry. Callers act on
 * each row inside its own tenant context; only an unroutable platform row is
 * deleted here, when its routed copy replaces it.
 */
final class ProviderEventInbox
{
    /** Every column but the (encrypted) payload. */
    private const array SUMMARY = ['id', 'provider', 'provider_event_id', 'provider_account_id', 'livemode', 'type', 'object_id', 'payment_attempt_id', 'payload_reduced', 'tenant_id', 'gateway_connection_id', 'status', 'attempts', 'last_error', 'received_at', 'processed_at', 'created_at', 'updated_at'];

    public function find(GatewayProvider $provider, string $providerEventId): ?ProviderEvent
    {
        return $this->unscoped()->where('provider', $provider->value)->where('provider_event_id', $providerEventId)->first();
    }

    public function findById(string $id): ?ProviderEvent
    {
        return $this->unscoped()->whereKey($id)->first();
    }

    /**
     * @return Collection<int, ProviderEvent>
     */
    public function staleReceived(CarbonImmutable $receivedBefore, int $limit): Collection
    {
        return $this->unscoped()
            ->select(self::SUMMARY)
            ->where('status', ProviderEventStatus::Received->value)
            ->where('received_at', '<=', $receivedBefore->utc()->format('Y-m-d H:i:s.u'))
            ->orderBy('received_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, ProviderEvent>
     */
    public function withStatus(ProviderEventStatus $status, int $limit): Collection
    {
        return $this->unscoped()->where('status', $status->value)->orderBy('received_at')->limit($limit)->get();
    }

    /**
     * Unroutable events without their payload: enough to find their
     * connection; the payload is read only for the ones routed.
     *
     * @return Collection<int, ProviderEvent>
     */
    public function unroutableSummaries(int $limit): Collection
    {
        return $this->unscoped()
            ->select(self::SUMMARY)
            ->where('status', ProviderEventStatus::Unroutable->value)
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();
    }

    /** Removes an unroutable platform row whose routed copy replaces it. */
    public function forgetUnroutable(ProviderEvent $event): void
    {
        $this->unscoped()->whereKey($event->id)->where('status', ProviderEventStatus::Unroutable->value)->whereNull('tenant_id')->delete();
    }

    /**
     * @return Builder<ProviderEvent>
     */
    private function unscoped(): Builder
    {
        return ProviderEvent::query()->withoutGlobalScopes();
    }
}
