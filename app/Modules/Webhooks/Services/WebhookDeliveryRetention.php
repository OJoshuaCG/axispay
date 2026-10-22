<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;

/**
 * Deletes webhook delivery attempts of every tenant older than
 * `webhooks.delivery_retention_days` (plan 15.6: 30 days, ADR-0067). On the
 * scope-bypass whitelist (config/tenancy.php, ADR-0031): it only deletes by
 * age and status and never reads a row's content.
 *
 * A `pending` attempt is never deleted, whatever its age: it is still owed to
 * the merchant and the outbox sweeper may queue it. The automatic schedule
 * ends in about 27 hours, so in practice every row this old is final.
 * The outbox (`webhook_events`) is kept: `GET /v1/events` has its own window.
 */
final class WebhookDeliveryRetention
{
    private const int CHUNK = 1000;

    /** Deletes in chunks of 1000 rows, so no single statement locks for long. */
    public function purge(): int
    {
        $total = 0;
        $retentionDays = max(1, config()->integer('axispay.webhooks.delivery_retention_days'));
        $before = now()->subDays($retentionDays);

        do {
            $deleted = WebhookDelivery::query()->withoutGlobalScopes()
                ->where('created_at', '<', $before)
                ->where('status', '!=', WebhookDeliveryStatus::Pending->value)
                ->limit(self::CHUNK)
                ->delete();
            $deleted = is_int($deleted) ? $deleted : 0;
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
