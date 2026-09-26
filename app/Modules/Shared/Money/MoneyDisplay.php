<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use App\Support\Locales;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Money\Currency;
use Illuminate\Support\Number;
use InvalidArgumentException;
use NumberFormatter;

/**
 * How people read money (ADR-0049): the number with the currency's decimals
 * and the ISO code after it, `12,500.00 MXN`, `1,200.00 USD`, never a bare
 * `$`. Grouping and decimal marks come from the viewer's formatting locale
 * (`es_MX` / `en_US`, so `,` and `.` in both languages).
 *
 * Exact: the integer part is formatted as an integer and the fraction is
 * appended from the decimal string, so no amount ever goes through a float.
 * The one place `<x-amount>` and the panels format money. Display only:
 * never parse the result back.
 */
final class MoneyDisplay
{
    /** Keeps the number and the code together when text wraps. */
    public const string SEPARATOR = "\u{00A0}";

    private function __construct() {}

    public static function format(Money $money, ?string $locale = null): string
    {
        return self::amount((string) $money->minorAmount, $money->currency->value, minor: true, locale: $locale);
    }

    /**
     * An absolute amount of any ISO 4217 currency (the sign is the caller's).
     *
     * @param  int|float|numeric-string  $value
     * @param  string|null  $locale  interface (`es`) or ICU (`de_DE`) locale; the viewer's when null
     */
    public static function amount(int|float|string $value, string $currency, bool $minor = false, ?string $locale = null): string
    {
        $code = strtoupper($currency);
        $digits = Currency::of($code)->getDefaultFractionDigits();
        $decimal = BigDecimal::of(is_float($value) ? self::floatString($value) : (string) $value)->abs();

        if ($minor) {
            $decimal = $decimal->withPointMovedLeft($digits);
        }

        return self::number($decimal->toScale($digits, Rounding::MODE), $locale).self::SEPARATOR.$code;
    }

    /**
     * An exact decimal with its own scale, formatted with the locale's marks.
     */
    public static function number(BigDecimal $decimal, ?string $locale = null): string
    {
        $icu = Locales::formattingLocale($locale ?? Number::defaultLocale());
        $formatter = new NumberFormatter($icu, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);

        $negative = $decimal->isNegative();
        [$integer, $fraction] = explode('.', $decimal->abs()->toString()) + [1 => ''];

        $formatted = self::integer($formatter, $integer);

        if ($fraction !== '') {
            $formatted .= $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).$fraction;
        }

        return ($negative ? '-' : '').$formatted;
    }

    private static function integer(NumberFormatter $formatter, string $integer): string
    {
        $big = BigInteger::of($integer);

        if ($big->isLessThanOrEqualTo(PHP_INT_MAX)) {
            $result = $formatter->format($big->toInt(), NumberFormatter::TYPE_INT64);

            if ($result === false) {
                throw new InvalidArgumentException('The amount could not be formatted.');
            }

            return $result;
        }

        // Beyond 64 bits (never a real amount): group by hand.
        $grouping = $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);

        return ltrim(strrev(implode(strrev($grouping), str_split(strrev($integer), 3))), $grouping);
    }

    /** A float as the shortest decimal string that reads back as the same float. */
    private static function floatString(float $value): string
    {
        $string = var_export($value, true);

        return str_contains($string, 'E') ? sprintf('%.14F', $value) : $string;
    }
}
