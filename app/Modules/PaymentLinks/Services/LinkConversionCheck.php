<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\Fx\Enums\FxMode;
use App\Modules\Fx\Services\FxConverter;
use App\Modules\Fx\Services\FxRates;
use App\Modules\PaymentLinks\Exceptions\PaymentLinkRejectedException;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Money\CurrencyLimits;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Data\TenantSettings;
use Brick\Math\BigDecimal;

/**
 * The checks of a link that will convert (plan 8.2, 10.5, ADR-0063), made
 * when it is created so the integrator learns about a problem at once and not
 * when a payer meets it:
 *
 *  - `fx_rate_invalid`: a fixed rate (the link's, else the tenant's) that is
 *    missing, or more than 30 % away from the latest stored Banxico FIX (a
 *    typo guard; skipped while no FIX is stored);
 *  - `amount_below_minimum_after_conversion`: the amount, converted with that
 *    fixed rate or, for `banxico_fix`, with the latest usable FIX and the
 *    tenant's markup, must reach the MXN minimum charge. Without a FIX there
 *    is nothing to estimate with and the link is accepted; the checkout
 *    checks again with the quote it makes.
 */
final readonly class LinkConversionCheck
{
    /** Percent a fixed rate may differ from the latest FIX. */
    private const int RATE_SANITY_PERCENT = 30;

    public function __construct(
        private FxRates $rates,
        private FxConverter $converter,
        private CurrencyLimits $limits,
    ) {}

    /**
     * @throws PaymentLinkRejectedException
     */
    public function assertAcceptable(Money $amount, FxMode $mode, ?ExchangeRate $linkRate, TenantSettings $settings): void
    {
        if (! $mode->converts()) {
            return;
        }

        $effective = $mode === FxMode::Fixed
            ? $this->fixedRate($linkRate, $settings)
            : $this->latestBanxicoRate($settings);

        if ($effective === null) {
            return;
        }

        $converted = $this->converter->apply($amount, $effective);

        if ($converted->minorAmount < $this->limits->minChargeMinor($converted->currency)) {
            $minimum = Money::ofMinor($this->limits->minChargeMinor($converted->currency), $converted->currency)->toDecimalString();

            throw PaymentLinkRejectedException::of(ApiErrorCode::AmountBelowMinimumAfterConversion, "The amount converts to {$converted->toDecimalString()} {$converted->currency->value}, below the minimum of {$minimum} {$converted->currency->value}.", 'amount');
        }
    }

    private function fixedRate(?ExchangeRate $linkRate, TenantSettings $settings): ExchangeRate
    {
        $rate = $linkRate ?? $settings->fxFixedRate
            ?? throw PaymentLinkRejectedException::of(ApiErrorCode::FxRateInvalid, 'A fixed conversion needs a rate: send fx.rate or set the fixed rate in the account\'s payment settings.', 'fx.rate');

        $fix = $this->rates->latestFix();

        if ($fix !== null && ! self::withinSanityRange($rate, $fix->rate)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::FxRateInvalid, 'The fixed rate is more than '.self::RATE_SANITY_PERCENT.' % away from the latest published exchange rate.', 'fx.rate');
        }

        return $rate;
    }

    private function latestBanxicoRate(TenantSettings $settings): ?ExchangeRate
    {
        $fix = $this->rates->usableFix();

        return $fix !== null ? ExchangeRate::of($fix->rate)->withMarkup($settings->fxMarkupBps) : null;
    }

    private static function withinSanityRange(ExchangeRate $rate, string $fix): bool
    {
        $reference = BigDecimal::of($fix);
        $difference = $rate->toBigDecimal()->minus($reference)->abs();

        // |rate - fix| / fix <= percent / 100, without dividing.
        return $difference->multipliedBy(100)->isLessThanOrEqualTo($reference->multipliedBy(self::RATE_SANITY_PERCENT));
    }
}
