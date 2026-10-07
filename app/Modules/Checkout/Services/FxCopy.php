<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Checkout\Data\CurrencyConfirmation;
use App\Modules\Fx\Data\FxConversion;
use App\Modules\Fx\Models\FxQuote;
use App\Modules\Shared\Money\MoneyDisplay;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The payer-facing words of a conversion (plan 13.5): the rate and where it
 * comes from, the merchant's markup (transparency, plan 13.5), the legend of
 * the payment page and the confirmation screen. Texts come from
 * lang/*\/checkout.php (`fx`), in the page's language; the numbers are the
 * quote's, exactly (never recomputed here).
 */
final class FxCopy
{
    /** The rate as people read it: at least two decimals, no trailing zeros beyond that (17.4225, 20.00). */
    public static function rate(string $effectiveRate): string
    {
        $text = rtrim((string) BigDecimal::of($effectiveRate)->toScale(6), '0');
        $decimals = strlen($text) - (int) strpos($text, '.') - 1;

        return $text.str_repeat('0', max(0, 2 - $decimals));
    }

    /** "17.4225 (Banxico FIX of 07/10/2026)" or "20.00 (exchange rate set by the merchant)". */
    public static function rateText(string $source, string $effectiveRate, ?string $rateDate): string
    {
        $sourceText = $source === FxQuote::SOURCE_BANXICO_FIX && $rateDate !== null
            ? __('checkout.fx.source.banxico_fix', ['date' => CarbonImmutable::parse($rateDate)->format(__('checkout.fx.date_format'))])
            : __('checkout.fx.source.merchant');

        return self::rate($effectiveRate).' ('.$sourceText.')';
    }

    /** The merchant's markup, or null when there is none (it is always disclosed when there is one). */
    public static function markupText(int $markupBps): ?string
    {
        if ($markupBps <= 0) {
            return null;
        }

        return __('checkout.fx.markup', ['percent' => BigDecimal::of($markupBps)->withPointMovedLeft(2)->toScale(2)->__toString()]);
    }

    /** The legend under the total of the payment page (plan 11.3): what a Mexican card would be charged. */
    public static function legend(FxConversion $estimate): string
    {
        return trim(__('checkout.fx.legend', [
            'amount' => MoneyDisplay::format($estimate->converted),
            'rate' => self::rateText($estimate->source, $estimate->effectiveRate->toString(), $estimate->rateDate),
            'markup' => self::markupText($estimate->markupBps) ?? '',
        ]));
    }

    /**
     * The confirmation screen's content, as the JSON the page script renders.
     *
     * @return array<string, mixed>
     */
    public static function confirmation(CurrencyConfirmation $confirmation): array
    {
        $quote = $confirmation->quote;
        $converted = $quote->converted();
        $amountLabel = MoneyDisplay::format($converted);

        return [
            'quote_id' => $quote->id,
            'title' => __('checkout.fx.confirm.title'),
            'intro' => __('checkout.fx.confirm.intro'),
            'original_caption' => __('checkout.fx.confirm.original'),
            'original_label' => MoneyDisplay::format($quote->original()),
            'amount_caption' => __('checkout.fx.confirm.amount'),
            'amount_label' => $amountLabel,
            'amount_minor' => $converted->minorAmount,
            'currency' => $converted->currency->value,
            'rate_caption' => __('checkout.fx.confirm.rate'),
            'rate_text' => self::rateText($quote->source, $quote->effective_rate, $quote->rate_date),
            'markup_text' => self::markupText($quote->markup_bps),
            'pay_label' => __('checkout.fx.confirm.pay', ['amount' => $amountLabel]),
            'cancel_label' => __('checkout.fx.confirm.cancel'),
        ];
    }
}
