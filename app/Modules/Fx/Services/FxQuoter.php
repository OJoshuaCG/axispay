<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\Fx\Data\FxConversion;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\Fx\Enums\FxUnavailableReason;
use App\Modules\Fx\Exceptions\FxUnavailableException;
use App\Modules\Fx\Models\FxQuote;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\CurrencyLimits;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Tenancy\Data\TenantSettings;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Works out and stores the conversion of a USD link (plan 13.4, ADR-0063).
 *
 * `fixed`: the link's own rate, else the tenant's `fx.fixed_rate`. The
 * integrator or the merchant already decided that rate, so the tenant markup
 * is NOT applied on top (plan 13.4), and the quote does not expire while
 * the link is open.
 *
 * `banxico_fix`: the newest stored FIX that is not flagged and not stale
 * (FxRates), with the tenant markup; valid for the tenant's quote validity.
 *
 * The converted amount must reach the MXN minimum charge (plan 8.2). The
 * checkout never calls Banxico: a missing or stale FIX blocks the mode.
 */
final readonly class FxQuoter
{
    public function __construct(
        private FxRates $rates,
        private FxConverter $converter,
        private CurrencyLimits $limits,
    ) {}

    /**
     * @throws FxUnavailableException
     */
    public function estimate(PaymentLink $link, TenantSettings $settings): FxConversion
    {
        $original = $link->money();

        $conversion = match ($link->fx_mode) {
            FxMode::Fixed => $this->fixed($link, $settings),
            FxMode::BanxicoFix => $this->banxico($settings),
            FxMode::None => throw new LogicException('A link that does not convert has no FX quote.'),
        };

        $converted = $this->converter->apply($original, $conversion['effective']);

        if ($converted->minorAmount < $this->limits->minChargeMinor($converted->currency)) {
            throw new FxUnavailableException(FxUnavailableReason::BelowMinimumAfterConversion);
        }

        return new FxConversion(
            source: $conversion['source'],
            rate: $conversion['rate'],
            rateDate: $conversion['rate_date'],
            exchangeRateId: $conversion['exchange_rate_id'],
            markupBps: $conversion['markup_bps'],
            effectiveRate: $conversion['effective'],
            original: $original,
            converted: $converted,
            expiresAt: $conversion['source'] === FxQuote::SOURCE_FIXED
                ? $link->expires_at
                : CarbonImmutable::now()->addMinutes($settings->fxQuoteValidityMinutes),
        );
    }

    /**
     * The quote the payer should see now (plan 13.4): the link's latest quote
     * when it is still valid and says exactly what a new one would (same rate,
     * same amounts), so a payer pressing "Pay" twice does not pile up quotes;
     * otherwise a new one. Current tenant context.
     *
     * @throws FxUnavailableException
     */
    public function current(PaymentLink $link, TenantSettings $settings): FxQuote
    {
        $conversion = $this->estimate($link, $settings);
        $latest = FxQuote::query()->where('payment_link_id', $link->id)->orderByDesc('id')->first();

        if ($latest !== null && ! $latest->isExpired() && self::says($latest, $conversion)) {
            return $latest;
        }

        return $this->store($link, $conversion);
    }

    /**
     * Stores the immutable quote of a link in the current tenant context.
     *
     * @throws FxUnavailableException
     */
    public function issue(PaymentLink $link, TenantSettings $settings): FxQuote
    {
        return $this->store($link, $this->estimate($link, $settings));
    }

    private function store(PaymentLink $link, FxConversion $conversion): FxQuote
    {
        $quote = new FxQuote;
        $quote->forceFill([
            'payment_link_id' => $link->id,
            'source' => $conversion->source,
            'exchange_rate_id' => $conversion->exchangeRateId,
            'rate' => $conversion->rate->toString(),
            'rate_date' => $conversion->rateDate,
            'markup_bps' => $conversion->markupBps,
            'effective_rate' => $conversion->effectiveRate->toString(),
            'original_amount_minor' => $conversion->original->minorAmount,
            'original_currency' => $conversion->original->currency,
            'converted_amount_minor' => $conversion->converted->minorAmount,
            'converted_currency' => $conversion->converted->currency,
            'expires_at' => $conversion->expiresAt,
        ])->save();

        return $quote;
    }

    private static function says(FxQuote $quote, FxConversion $conversion): bool
    {
        return $quote->source === $conversion->source
            && $quote->effective_rate === $conversion->effectiveRate->toString()
            && $quote->original_amount_minor === $conversion->original->minorAmount
            && $quote->converted_amount_minor === $conversion->converted->minorAmount;
    }

    /**
     * @return array{source: string, rate: ExchangeRate, effective: ExchangeRate, rate_date: string|null, exchange_rate_id: string|null, markup_bps: int}
     *
     * @throws FxUnavailableException
     */
    private function fixed(PaymentLink $link, TenantSettings $settings): array
    {
        $linkRate = $link->fx_fixed_rate !== null ? ExchangeRate::tryOf($link->fx_fixed_rate) : null;
        $rate = $linkRate ?? $settings->fxFixedRate ?? throw new FxUnavailableException(FxUnavailableReason::FixedRateMissing);

        return [
            'source' => FxQuote::SOURCE_FIXED,
            'rate' => $rate,
            'effective' => $rate,
            'rate_date' => null,
            'exchange_rate_id' => null,
            'markup_bps' => 0,
        ];
    }

    /**
     * @return array{source: string, rate: ExchangeRate, effective: ExchangeRate, rate_date: string|null, exchange_rate_id: string|null, markup_bps: int}
     *
     * @throws FxUnavailableException
     */
    private function banxico(TenantSettings $settings): array
    {
        $fix = $this->rates->usableFix() ?? throw new FxUnavailableException(FxUnavailableReason::NoFreshRate);
        $rate = ExchangeRate::of($fix->rate);

        return [
            'source' => FxQuote::SOURCE_BANXICO_FIX,
            'rate' => $rate,
            'effective' => $rate->withMarkup($settings->fxMarkupBps),
            'rate_date' => $fix->rate_date,
            'exchange_rate_id' => $fix->id,
            'markup_bps' => $settings->fxMarkupBps,
        ];
    }
}
