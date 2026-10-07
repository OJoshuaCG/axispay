<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Fx\Models\FxQuote;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * The `fx` block of a payment (plan 10.6, 13.6, ADR-0063): the conversion that
 * was applied, as the integrator needs it to reconcile (the charged amount is
 * in MXN, the original in USD):
 *
 *  - `mode`: `fixed` | `banxico_fix`, the mode that was applied;
 *  - `source`: who set the rate, `merchant` (a fixed rate of the tenant or
 *    the link) or `banxico_fix`;
 *  - `rate` and `rate_date`: the published or fixed rate and, for Banxico,
 *    the publication date; `markup_bps` and `effective_rate`: the merchant's
 *    markup and the rate that produced the charge;
 *  - `original_amount` and `original_currency`: the link's amount.
 *
 * Null on payments with no conversion; the pre-payment validation body
 * carries `{applied: false}` instead (it always has an `fx` object).
 */
final class FxBlock
{
    /**
     * @return array<string, mixed>|null
     */
    public static function of(PaymentAttempt $attempt): ?array
    {
        $quote = $attempt->fx_quote_id !== null ? $attempt->fxQuote : null;

        return $quote !== null ? self::ofQuote($quote) : null;
    }

    /**
     * The body of the pre-payment validation: always an object.
     *
     * @return array<string, mixed>
     */
    public static function forValidation(PaymentAttempt $attempt): array
    {
        return self::of($attempt) ?? ['applied' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofQuote(FxQuote $quote): array
    {
        return [
            'applied' => true,
            'mode' => $quote->source,
            'source' => $quote->source === FxQuote::SOURCE_BANXICO_FIX ? FxQuote::SOURCE_BANXICO_FIX : 'merchant',
            'rate' => $quote->rate,
            'rate_date' => $quote->rate_date,
            'markup_bps' => $quote->markup_bps,
            'effective_rate' => $quote->effective_rate,
            'original_amount' => $quote->original()->toDecimalString(),
            'original_currency' => $quote->original_currency->value,
        ];
    }
}
