<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Money\AmountParser;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Exceptions\InvalidAmountException;

/**
 * Plan section 8.2 / 8.5: parsing rules for API amounts.
 */
function parseAmount(mixed $amount, mixed $currency = 'USD'): int
{
    return app(AmountParser::class)->parse($amount, $currency)->minorAmount;
}

function amountErrorCode(mixed $amount, mixed $currency = 'USD'): ApiErrorCode
{
    try {
        parseAmount($amount, $currency);
    } catch (InvalidAmountException $e) {
        return $e->errorCode;
    }

    throw new RuntimeException('Expected the amount to be rejected.');
}

it('parses valid decimal strings into minor units', function (string $amount, string $currency, int $expectedMinor): void {
    expect(parseAmount($amount, $currency))->toBe($expectedMinor);
})->with([
    'two decimals' => ['150.50', 'USD', 15050],
    'one decimal' => ['150.5', 'USD', 15050],
    'integer' => ['150', 'USD', 15000],
    'minimum USD' => ['0.50', 'USD', 50],
    'maximum USD' => ['10000.00', 'USD', 1_000_000],
    'minimum MXN' => ['10.00', 'MXN', 1_000],
    'maximum MXN' => ['200000', 'MXN', 20_000_000],
    'lowercase currency is normalized' => ['99.99', 'usd', 9_999],
    'mixed case currency is normalized' => ['1500.00', 'Mxn', 150_000],
]);

it('uses the regex of plan section 8.2 for exponent 2, anchored at the absolute end', function (): void {
    // The D modifier makes `$` reject a trailing newline, which plain `$` accepts.
    expect(AmountParser::patternFor(CurrencyCode::USD))
        ->toBe('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$/D');
});

it('rejects JSON numbers with amount_must_be_string', function (mixed $amount): void {
    expect(amountErrorCode($amount))->toBe(ApiErrorCode::AmountMustBeString);
})->with([
    'float' => [150.5],
    'integer' => [150],
    'zero' => [0],
    'float zero' => [0.0],
]);

it('rejects malformed amount strings with amount_invalid', function (mixed $amount): void {
    expect(amountErrorCode($amount))->toBe(ApiErrorCode::AmountInvalid);
})->with([
    'too many decimals' => ['1.505'],
    'leading zero' => ['01.00'],
    'negative sign' => ['-1.00'],
    'positive sign' => ['+1.00'],
    'thousands separator' => ['1,000.00'],
    'comma decimal separator' => ['1,50'],
    'scientific notation' => ['1e3'],
    'leading space' => [' 1.00'],
    'trailing space' => ['1.00 '],
    'trailing newline' => ["1.00\n"],
    'trailing dot' => ['1.'],
    'leading dot' => ['.5'],
    'letters' => ['abc'],
    'hex' => ['0x10'],
    'full-width digits' => ['１００'],
    'thirteen integer digits' => ['1000000000000'],
    'boolean' => [true],
    'array' => [['1.00']],
]);

it('rejects unsupported currencies with currency_not_supported', function (mixed $currency): void {
    expect(amountErrorCode('10.00', $currency))->toBe(ApiErrorCode::CurrencyNotSupported);
})->with([
    'EUR' => ['EUR'],
    'two letters' => ['US'],
    'padded' => ['USD '],
    'number' => [840],
]);

it('rejects amounts outside the configured limits', function (string $amount, string $currency, ApiErrorCode $code): void {
    expect(amountErrorCode($amount, $currency))->toBe($code);
})->with([
    'zero' => ['0', 'USD', ApiErrorCode::AmountBelowMinimum],
    'just below USD minimum' => ['0.49', 'USD', ApiErrorCode::AmountBelowMinimum],
    'just below MXN minimum' => ['9.99', 'MXN', ApiErrorCode::AmountBelowMinimum],
    'just above USD maximum' => ['10000.01', 'USD', ApiErrorCode::AmountAboveMaximum],
    'just above MXN maximum' => ['200000.01', 'MXN', ApiErrorCode::AmountAboveMaximum],
    'largest well-formed amount' => ['999999999999.99', 'USD', ApiErrorCode::AmountAboveMaximum],
]);

it('reports missing parameters with parameter_missing', function (mixed $amount, mixed $currency): void {
    expect(amountErrorCode($amount, $currency))->toBe(ApiErrorCode::ParameterMissing);
})->with([
    'no amount' => [null, 'USD'],
    'empty amount' => ['', 'USD'],
    'no currency' => ['10.00', null],
]);

it('names the offending parameter and uses HTTP 400', function (): void {
    try {
        app(AmountParser::class)->parse(150.5, 'USD', amountParam: 'refund.amount');
    } catch (InvalidAmountException $e) {
        expect($e->param)->toBe('refund.amount')
            ->and($e->status())->toBe(400);

        return;
    }

    throw new RuntimeException('Expected the amount to be rejected.');
});

it('reads the limits from configuration', function (): void {
    config(['paylink.currencies.USD.min_charge_minor' => 1_000]);

    expect(amountErrorCode('9.99'))->toBe(ApiErrorCode::AmountBelowMinimum)
        ->and(parseAmount('10.00'))->toBe(1_000);
});

it('rejects a currency that is disabled in configuration', function (): void {
    config(['paylink.currencies.MXN.enabled' => false]);

    expect(amountErrorCode('100.00', 'MXN'))->toBe(ApiErrorCode::CurrencyNotSupported);
});
