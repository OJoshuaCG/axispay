<?php

declare(strict_types=1);

use App\Modules\Fx\Services\LineItemConverter;
use App\Modules\PaymentLinks\Data\LineItem;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;

/*
 * The hosted breakdown of a converted link (spec B6): every line is
 * converted on its own (round_half_up once) and the line flagged
 * `absorbs_rounding` takes the residual, so the converted lines add up to
 * exactly the amount charged in MXN. Worked example: 12.30 USD x 20 =
 * 246.00 MXN.
 */

function usdLine(string $label, int $minor, bool $absorbs = false): LineItem
{
    return new LineItem($label, Money::ofMinor($minor, CurrencyCode::USD), $absorbs);
}

/**
 * @param  list<LineItem>  $lines
 */
function usdTotal(array $lines): Money
{
    return Money::ofMinor(array_sum(array_map(static fn (LineItem $line): int => $line->amount->minorAmount, $lines)), CurrencyCode::USD);
}

it('converts each line and leaves the residual on the absorbing line (rate 20, exact)', function (): void {
    $lines = [usdLine('Purchase', 1_000), usdLine('Processing charge', 150, absorbs: true), usdLine('VAT', 80)];
    $rate = ExchangeRate::of('20');
    $total = usdTotal($lines)->convertTo(CurrencyCode::MXN, $rate);

    $converted = (new LineItemConverter)->convert($lines, $total, $rate);

    expect($total->minorAmount)->toBe(24_600)
        ->and($converted)->not->toBeNull()
        ->and(array_map(static fn (LineItem $line): int => $line->amount->minorAmount, (array) $converted))->toBe([20_000, 3_000, 1_600])
        ->and(array_map(static fn (LineItem $line): string => $line->label, (array) $converted))->toBe(['Purchase', 'Processing charge', 'VAT'])
        ->and(array_map(static fn (LineItem $line): bool => $line->absorbsRounding, (array) $converted))->toBe([false, true, false]);
});

it('puts the rounding residual on the flagged line so the lines add up to the charged amount', function (): void {
    // 10.00 x 17.4225 = 174.225 -> 174.23 (half up); the total 12.30 x 17.4225 = 214.29675 -> 214.30.
    $lines = [usdLine('Purchase', 1_000), usdLine('Fee and VAT', 230, absorbs: true)];
    $rate = ExchangeRate::of('17.4225');
    $total = usdTotal($lines)->convertTo(CurrencyCode::MXN, $rate);

    $converted = (new LineItemConverter)->convert($lines, $total, $rate);

    expect($total->minorAmount)->toBe(21_430)
        ->and(array_map(static fn (LineItem $line): int => $line->amount->minorAmount, (array) $converted))->toBe([17_423, 4_007]);
});

/**
 * @return list<int> line amounts in USD cents; the first one is the absorbing line
 */
function lineAmountsOf(string $set): array
{
    return match ($set) {
        'mixed' => [5_000, 333, 777, 1_234],
        'many tiny' => [999, 1, 1, 1, 1],
        default => [10_000, 55, 55],
    };
}

it('always adds up exactly to the converted total', function (string $rate, string $set): void {
    $lines = [];

    foreach (lineAmountsOf($set) as $index => $minor) {
        $lines[] = usdLine('Line '.$index, $minor, absorbs: $index === 0);
    }

    $exchange = ExchangeRate::of($rate);
    $total = usdTotal($lines)->convertTo(CurrencyCode::MXN, $exchange);
    $converted = (new LineItemConverter)->convert($lines, $total, $exchange);

    expect($converted)->not->toBeNull()
        ->and(array_sum(array_map(static fn (LineItem $line): int => $line->amount->minorAmount, (array) $converted)))->toBe($total->minorAmount)
        ->and(array_map(static fn (LineItem $line): CurrencyCode => $line->amount->currency, (array) $converted))->each->toBe(CurrencyCode::MXN);
})->with([
    'banxico-like' => ['17.4225', 'mixed'],
    'six decimals' => ['18.123457', 'many tiny'],
    'rate below one' => ['0.9', 'big and small'],
]);

it('gives up (null) when the residual would not be a positive amount', function (): void {
    // Ten 0.01 USD lines at 0.5 round up to 0.01 MXN each (0.005 half up); the total 0.11 x 0.5 = 0.055 -> 0.06,
    // so the flagged line would be left with -0.04.
    $lines = [usdLine('Flagged', 1, absorbs: true), ...array_map(static fn (int $i): LineItem => usdLine('Small '.$i, 1), range(1, 10))];
    $rate = ExchangeRate::of('0.5');
    $total = usdTotal($lines)->convertTo(CurrencyCode::MXN, $rate);

    expect((new LineItemConverter)->convert($lines, $total, $rate))->toBeNull();
});

it('gives up (null) without exactly one absorbing line', function (array $flags): void {
    $lines = [];

    foreach ($flags as $index => $flag) {
        $lines[] = usdLine('Line '.$index, 500, absorbs: $flag === true);
    }

    $rate = ExchangeRate::of('20');
    $total = usdTotal($lines)->convertTo(CurrencyCode::MXN, $rate);

    expect((new LineItemConverter)->convert($lines, $total, $rate))->toBeNull();
})->with(['none' => [[false, false]], 'two' => [[true, true]]]);
