<?php

declare(strict_types=1);

use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Support\Locales;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Number;

/**
 * Money shown to people (ADR-0049): number with the currency's decimals and
 * the ISO code, grouping `,` and decimal `.` in both languages (es → es_MX,
 * en → en_US; ICU 77 output confirmed with PHP intl).
 */
const NBSP = "\u{00A0}";

it('formats with the market region of each language', function (string $locale): void {
    Locales::apply($locale);

    expect(MoneyDisplay::format(Money::ofMinor(1_250_000, CurrencyCode::MXN)))->toBe('12,500.00'.NBSP.'MXN')
        ->and(MoneyDisplay::format(Money::ofMinor(120_000, CurrencyCode::USD)))->toBe('1,200.00'.NBSP.'USD')
        ->and(MoneyDisplay::format(Money::ofMinor(50, CurrencyCode::USD)))->toBe('0.50'.NBSP.'USD')
        ->and(Number::defaultLocale())->toBe(['es' => 'es_MX', 'en' => 'en_US'][$locale]);
})->with(['es', 'en']);

it('never goes through a float', function (): void {
    Locales::apply('es');

    expect(MoneyDisplay::format(Money::ofMinor(PHP_INT_MAX, CurrencyCode::MXN)))->toBe('92,233,720,368,547,758.07'.NBSP.'MXN')
        ->and(MoneyDisplay::amount('123456789012345678901234.5', 'USD'))->toBe('123,456,789,012,345,678,901,234.50'.NBSP.'USD');
});

it('uses the decimals of any ISO currency, minor units and explicit locales', function (): void {
    Locales::apply('en');

    expect(MoneyDisplay::amount(129900, 'clp', minor: true))->toBe('129,900'.NBSP.'CLP')
        ->and(MoneyDisplay::amount(1250.5, 'USD'))->toBe('1,250.50'.NBSP.'USD')
        ->and(MoneyDisplay::amount('-3.9', 'EUR', locale: 'de_DE'))->toBe('3,90'.NBSP.'EUR')
        ->and(MoneyDisplay::amount('5', 'KWD'))->toBe('5.000'.NBSP.'KWD');
});

it('renders <x-amount> with the same formatter, sign and code', function (string $locale): void {
    Locales::apply($locale);

    expect(Blade::render('<x-amount :value="1250.5" currency="USD" />'))->toContain('+1,250.50'.NBSP.'USD')->toContain('text-amount-positive')
        ->and(Blade::render('<x-amount :value="-42" currency="MXN" />'))->toContain("\u{2212}42.00".NBSP.'MXN')
        ->and(Blade::render('<x-amount :value="129900" currency="CLP" minor :signed="false" />'))->toContain('>129,900'.NBSP.'CLP<');
})->with(['es', 'en']);

it('maps interface locales to formatting locales and leaves ICU locales alone', function (): void {
    expect(Locales::formattingLocale('es'))->toBe('es_MX')
        ->and(Locales::formattingLocale('en'))->toBe('en_US')
        ->and(Locales::formattingLocale('de_DE'))->toBe('de_DE');
});
