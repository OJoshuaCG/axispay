<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use Brick\Money\Currency;

/**
 * Currencies the platform can charge in (plan section 8.1).
 *
 * The minor-unit exponent comes from brick/money's ISO 4217 data so it is never
 * hand-maintained. Whether a currency is enabled and its charge limits live in
 * config/paylink.php (see CurrencyLimits).
 */
enum CurrencyCode: string
{
    case USD = 'USD';
    case MXN = 'MXN';

    /**
     * Resolves user input, normalizing lowercase codes ("usd" -> USD) as the
     * API contract allows (plan section 10.5). Returns null for anything else.
     */
    public static function tryFromInput(mixed $value): ?self
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/D', $value) !== 1) {
            return null;
        }

        return self::tryFrom(strtoupper($value));
    }

    /**
     * Number of decimal places of the minor unit (2 for USD and MXN).
     */
    public function exponent(): int
    {
        return $this->toBrick()->getDefaultFractionDigits();
    }

    public function toBrick(): Currency
    {
        return Currency::of($this->value);
    }
}
