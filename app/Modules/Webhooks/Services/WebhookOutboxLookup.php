<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cross-tenant reader of the outbox sweeper (plan 15.4 step 3; on the
 * scope-bypass whitelist, config/tenancy.php, ADR-0057). Identifiers only:
 * every row is then handled inside its own tenant context.
 */
final class WebhookOutboxLookup
{
    /**
     * Domain events never published (their transaction predates Phase 5, or
     * the publication failed) recorded before `$before`.
     *
     * @return list<array{id: string, tenant_id: string, livemode: bool}>
     */
    public function unpublishedEvents(CarbonImmutable $before, int $limit): array
    {
        $rows = DomainEvent::query()->withoutGlobalScopes()
            ->whereNull('published_at')
            ->where('occurred_at', '<=', $before)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'tenant_id', 'livemode']);

        return array_values($rows->map(static fn (DomainEvent $row): array => ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'livemode' => (bool) $row->livemode])->all());
    }

    /**
     * Pending deliveries that are due, not being sent, and whose job was
     * never queued or was queued before `$queuedBefore` without running.
     *
     * @return list<array{id: string, tenant_id: string, livemode: bool}>
     */
    public function dueDeliveries(CarbonImmutable $now, CarbonImmutable $queuedBefore, int $limit): array
    {
        $rows = WebhookDelivery::query()->withoutGlobalScopes()
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->where('scheduled_at', '<=', $now)
            ->where(static fn (Builder $q): Builder => $q->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->where(static fn (Builder $q): Builder => $q->whereNull('queued_at')->orWhere('queued_at', '<=', $queuedBefore))
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get(['id', 'tenant_id', 'livemode']);

        return array_values($rows->map(static fn (WebhookDelivery $row): array => ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'livemode' => (bool) $row->livemode])->all());
    }
}
