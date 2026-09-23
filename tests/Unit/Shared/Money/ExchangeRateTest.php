<?php

declare(strict_types=1);

use App\Modules\Shared\Money\ExchangeRate;

/**
 * Exchange rates are DECIMAL(18,6) strings (plan sections 7 and 8.4).
 */
it('normalizes valid rates to six decimals', function (string $input, string $expected): void {
    expect(ExchangeRate::of($input)->toString())->toBe($expected);
})->with([
    ['17.25', '17.250000'],
    ['17.422500', '17.422500'],
    ['1', '1.000000'],
    ['0.000001', '0.000001'],
    ['999999999999.999999', '999999999999.999999'],
]);

it('rejects values that are not positive DECIMAL(18,6) strings', function (string $input): void {
    ExchangeRate::of($input);
})->throws(InvalidArgumentException::class)->with([
    'zero' => ['0'],
    'zero with decimals' => ['0.000000'],
    'negative' => ['-17.25'],
    'seven decimals' => ['17.1234567'],
    'thirteen integer digits' => ['1000000000000'],
    'scientific notation' => ['1.7e1'],
    'empty' => [''],
    'padded' => [' 17.25'],
    'comma' => ['17,25'],
]);

it('never accepts floats', function (): void {
    expect(ExchangeRate::tryOf(17.25))->toBeNull()
        ->and(ExchangeRate::tryOf('17.25')?->toString())->toBe('17.250000');
});

it('applies the markup and rounds HALF_UP to six decimals', function (string $rate, int $bps, string $expected): void {
    expect(ExchangeRate::of($rate)->withMarkup($bps)->toString())->toBe($expected);
})->with([
    'no markup' => ['17.250000', 0, '17.250000'],
    'one percent' => ['17.250000', 100, '17.422500'],
    'maximum markup (10%)' => ['17.250000', 1_000, '18.975000'],
    'rounds down below half' => ['1.000001', 50, '1.005001'],
    'exact half rounds up (half-even would round down)' => ['0.000003', 5_000, '0.000005'],
]);

it('rejects a negative markup', function (): void {
    ExchangeRate::of('17.25')->withMarkup(-1);
})->throws(InvalidArgumentException::class);
