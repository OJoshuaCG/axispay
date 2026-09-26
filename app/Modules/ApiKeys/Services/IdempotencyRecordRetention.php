<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

use App\Modules\ApiKeys\Models\IdempotencyRecord;

/**
 * Deletes expired idempotency records of every tenant (plan 7.8: kept 24
 * hours). On the scope-bypass whitelist (config/tenancy.php, ADR-0031): it
 * only deletes by expiry and never reads a record's content.
 */
final class IdempotencyRecordRetention
{
    private const int CHUNK = 1000;

    /** Deletes in chunks of 1000 rows, so no single statement locks for long. */
    public function purge(): int
    {
        $total = 0;
        $now = now();

        do {
            $deleted = IdempotencyRecord::query()->withoutGlobalScopes()
                ->where('expires_at', '<', $now)
                ->limit(self::CHUNK)
                ->delete();
            $deleted = is_int($deleted) ? $deleted : 0;
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
