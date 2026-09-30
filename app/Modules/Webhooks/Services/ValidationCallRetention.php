<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Models\ValidationCall;

/**
 * Deletes pre-payment validation calls of every tenant older than
 * `pre_payment_validation.retention_days` (plan 7.6: 30 days). On the
 * scope-bypass whitelist (config/tenancy.php, ADR-0031): it only deletes by
 * age and never reads a row's content.
 */
final class ValidationCallRetention
{
    private const int CHUNK = 1000;

    /** Deletes in chunks of 1000 rows, so no single statement locks for long. */
    public function purge(): int
    {
        $total = 0;
        $before = now()->subDays(max(1, config()->integer('axispay.pre_payment_validation.retention_days')));

        do {
            $deleted = ValidationCall::query()->withoutGlobalScopes()
                ->where('created_at', '<', $before)
                ->limit(self::CHUNK)
                ->delete();
            $deleted = is_int($deleted) ? $deleted : 0;
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
