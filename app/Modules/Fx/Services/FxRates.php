<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\Fx\Models\StoredExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Reads the stored Banxico FIX (plan 13.3). The checkout never calls
 * Banxico: this is its only source of a market rate. A FIX flagged for
 * review is never offered, and a FIX older than the maximum age is stale
 * (counted in calendar days of Mexico City, where it is published).
 */
final class FxRates
{
    private const string PUBLICATION_TIMEZONE = 'America/Mexico_City';

    /** The newest FIX that may be charged, even if stale (see isStale). */
    public function latestFix(): ?StoredExchangeRate
    {
        return StoredExchangeRate::query()
            ->where('source', StoredExchangeRate::SOURCE_BANXICO_FIX)
            ->where('base_currency', 'USD')
            ->where('quote_currency', 'MXN')
            ->where('requires_review', false)
            ->orderByDesc('rate_date')
            ->first();
    }

    /** The newest FIX that is also fresh enough to charge, or null. */
    public function usableFix(): ?StoredExchangeRate
    {
        $fix = $this->latestFix();

        return $fix !== null && ! $this->isStale($fix) ? $fix : null;
    }

    public function isStale(StoredExchangeRate $fix): bool
    {
        $today = CarbonImmutable::now(self::PUBLICATION_TIMEZONE)->startOfDay();
        $published = CarbonImmutable::createFromFormat('Y-m-d', $fix->rate_date, self::PUBLICATION_TIMEZONE);

        return $published === null || (int) $published->startOfDay()->diffInDays($today) > config()->integer('axispay.fx.max_rate_age_days');
    }
}
