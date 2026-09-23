<?php

declare(strict_types=1);

use App\Modules\Shared\Money\AmountParser;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money as BrickMoney;

/**
 * Plan sections 8.3 to 8.5: representation, rounding and conversion.
 */
it('represents amounts as exact decimal strings plus minor units', function (int $minor, string $currency, string $amount): void {
    expect(Money::ofMinor($minor, CurrencyCode::from($currency))->toApiArray())->toBe([
        'amount' => $amount,
        'amount_minor' => $minor,
        'currency' => $currency,
    ]);
})->with([
    [15050, 'MXN', '150.50'],
    [150000, 'USD', '1500.00'],
    [5, 'USD', '0.05'],
    [0, 'USD', '0.00'],
    [100, 'MXN', '1.00'],
]);

it('knows the minor unit exponent of every supported currency', function (CurrencyCode $currency): void {
    expect($currency->exponent())->toBe(2);
})->with(CurrencyCode::cases());

it('round-trips minor -> string -> minor for any valid integer', function (): void {
    mt_srand(20260923);
    $samples = [0, 1, 9, 10, 99, 100, 101, 999, 1_000, 15_050, 99_999_999_999_999];

    for ($i = 0; $i < 2_000; $i++) {
        $samples[] = mt_rand(0, 99_999_999_999_999);
    }

    foreach ($samples as $minor) {
        foreach (CurrencyCode::cases() as $currency) {
            $string = Money::ofMinor($minor, $currency)->toDecimalString();
            $pattern = AmountParser::patternFor($currency);

            expect(preg_match($pattern, $string))->toBe(1, "{$string} does not match the API format")
                ->and(BrickMoney::of($string, $currency->value)->getMinorAmount()->toInt())->toBe($minor);
        }
    }
});

it('rounds percentages HALF_UP once, to minor units', function (int $minor, int $bps, int $expected): void {
    expect(Money::ofMinor($minor, CurrencyCode::MXN)->percentage($bps)->minorAmount)->toBe($expected);
})->with([
    'exact' => [10_000, 150, 150],
    'rounds .75 up' => [15_050, 150, 226],
    'exact half rounds up' => [100, 50, 1],
    'below half rounds down' => [10, 50, 0],
    'zero bps' => [15_050, 0, 0],
    'full amount' => [15_050, 10_000, 15_050],
]);

it('converts with the effective rate and rounds HALF_UP once at the end', function (int $minor, string $rate, int $expected): void {
    $converted = Money::ofMinor($minor, CurrencyCode::USD)
        ->convertTo(CurrencyCode::MXN, ExchangeRate::of($rate));

    expect($converted->minorAmount)->toBe($expected)
        ->and($converted->currency)->toBe(CurrencyCode::MXN);
})->with([
    'exact' => [150_000, '17.422500', 2_613_375],
    'exact half rounds up (half-even would give 2)' => [5, '0.500000', 3],
    'exact half on a larger amount' => [1, '17.500000', 18],
    'just below half rounds down' => [1, '17.499999', 17],
    'just above half rounds up' => [1, '17.500001', 18],
    'identity rate' => [12_345, '1', 12_345],
    'many decimals' => [99_999, '19.876543', 1_987_634],
]);

it('refuses arithmetic across currencies', function (): void {
    Money::ofMinor(100, CurrencyCode::USD)->plus(Money::ofMinor(100, CurrencyCode::MXN));
})->throws(InvalidArgumentException::class);

it('adds and subtracts in minor units', function (): void {
    $a = Money::ofMinor(15_050, CurrencyCode::USD);
    $b = Money::ofMinor(50, CurrencyCode::USD);

    expect($a->plus($b)->minorAmount)->toBe(15_100)
        ->and($a->minus($b)->minorAmount)->toBe(15_000)
        ->and($a->equals(Money::ofMinor(15_050, CurrencyCode::USD)))->toBeTrue();
});

it('rejects a brick amount whose scale does not match the currency', function (): void {
    Money::fromBrick(BrickMoney::of('1.5', 'USD', new CustomContext(4)));
})->throws(InvalidArgumentException::class);
