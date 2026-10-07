<?php

declare(strict_types=1);

namespace App\Modules\Fx\Actions;

use App\Modules\Fx\Data\BanxicoFix;
use App\Modules\Fx\Models\StoredExchangeRate;
use App\Modules\Fx\Services\FxRateAlerts;
use App\Modules\Fx\Services\FxRates;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores the FIX of a publication date once (plan 13.3). Idempotent: when
 * the date is already stored, nothing changes, so the several daily runs of
 * FetchBanxicoFixJob are harmless.
 *
 * Sanity check: a FIX that differs from the previous usable one by MORE than
 * `axispay.fx.sanity_deviation_percent` is stored flagged `requires_review`
 * (kept, but never charged: FxRates skips it) and the superadmins are told.
 * The first FIX ever stored has nothing to compare with and is accepted.
 */
final readonly class StoreBanxicoFix
{
    public function __construct(
        private FxRates $rates,
        private FxRateAlerts $alerts,
    ) {}

    /** The stored row, or null when that date was already there. */
    public function handle(BanxicoFix $fix): ?StoredExchangeRate
    {
        $exists = StoredExchangeRate::query()
            ->where('source', StoredExchangeRate::SOURCE_BANXICO_FIX)
            ->where('base_currency', 'USD')
            ->where('quote_currency', 'MXN')
            ->where('rate_date', $fix->date)
            ->exists();

        if ($exists) {
            return null;
        }

        $previous = $this->rates->latestFix();
        $requiresReview = $previous !== null && $this->deviatesTooMuch($previous->rate, $fix->rate->toString());

        $row = new StoredExchangeRate;
        $row->forceFill([
            'source' => StoredExchangeRate::SOURCE_BANXICO_FIX,
            'base_currency' => 'USD',
            'quote_currency' => 'MXN',
            'rate' => $fix->rate->toString(),
            'rate_date' => $fix->date,
            'fetched_at' => now(),
            'raw_payload' => $fix->rawPayload,
            'requires_review' => $requiresReview,
        ]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return null; // another run stored the same date first
        }

        if ($requiresReview) {
            $this->alerts->requiresReview($row);
        }

        return $row;
    }

    private function deviatesTooMuch(string $previous, string $new): bool
    {
        $previousRate = BigDecimal::of($previous);
        $difference = BigDecimal::of($new)->minus($previousRate)->abs();
        $limitPercent = config()->integer('axispay.fx.sanity_deviation_percent');

        // |new - previous| / previous > limit / 100, without dividing.
        return $difference->multipliedBy(100)->isGreaterThan($previousRate->multipliedBy($limitPercent));
    }
}
