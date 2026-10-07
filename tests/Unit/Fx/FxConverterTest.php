<?php

declare(strict_types=1);

use App\Modules\Fx\Enums\FxMode;
use App\Modules\Fx\Services\FxConverter;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;

/**
 * The arithmetic of a conversion (plan 8.4): effective rate =
 * round_half_up(rate * (1 + bps / 10000), 6), converted =
 * round_half_up(original_minor * effective rate), rounded once.
 */
it('converts the priced USD total of a pbx top-up with the fixed rate 20 (12.30 USD x 20 = 246.00 MXN)', function (): void {
    $converted = (new FxConverter)->apply(Money::ofMinor(1_230, CurrencyCode::USD), ExchangeRate::of('20'));

    expect($converted->currency)->toBe(CurrencyCode::MXN)
        ->and($converted->minorAmount)->toBe(24_600)
        ->and($converted->toDecimalString())->toBe('246.00');
});

it('rounds half up, once, at the end', function (int $originalMinor, string $rate, int $expectedMinor): void {
    expect((new FxConverter)->apply(Money::ofMinor($originalMinor, CurrencyCode::USD), ExchangeRate::of($rate))->minorAmount)->toBe($expectedMinor);
})->with([
    'exact .5 rounds up' => [1, '17.5', 18],
    'just below .5' => [1, '17.499999', 17],
    'plan example 1,500.00 USD at 17.4225' => [150_000, '17.4225', 2_613_375],
    'one cent at 20' => [1, '20', 20],
    'large amount keeps precision' => [999_999_999, '17.123456', 17_123_455_983],
]);

it('applies the markup to the rate before converting, rounding the effective rate to six decimals', function (int $bps, string $effective, int $expectedMinor): void {
    $rate = ExchangeRate::of('17.25')->withMarkup($bps);

    expect($rate->toString())->toBe($effective)
        ->and((new FxConverter)->apply(Money::ofMinor(100_000, CurrencyCode::USD), $rate)->minorAmount)->toBe($expectedMinor);
})->with([
    'no markup' => [0, '17.250000', 1_725_000],
    '1.00 %' => [100, '17.422500', 1_742_250],
    'maximum 10 %' => [1_000, '18.975000', 1_897_500],
]);

it('knows the modes that convert', function (): void {
    expect(FxMode::Fixed->converts())->toBeTrue()
        ->and(FxMode::BanxicoFix->converts())->toBeTrue()
        ->and(FxMode::None->converts())->toBeFalse();
});
