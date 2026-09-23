<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Exchange rate stored as DECIMAL(18,6): at most 12 integer digits and exactly
 * six decimals. Carried as a string / BigDecimal, never as a float
 * (rules.md rule 1, plan sections 7 and 8.4).
 */
final readonly class ExchangeRate
{
    public const int SCALE = 6;

    private const string PATTERN = '/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,6})?$/D';

    private function __construct(private BigDecimal $value) {}

    /**
     * Parses a decimal string (API input or a DECIMAL(18,6) column value).
     *
     * @throws InvalidArgumentException when the value is not a positive decimal
     *                                  string that fits DECIMAL(18,6)
     */
    public static function of(string $rate): self
    {
        if (preg_match(self::PATTERN, $rate) !== 1) {
            throw new InvalidArgumentException('An exchange rate must be a decimal string with up to 12 integer digits and 6 decimals.');
        }

        $value = BigDecimal::of($rate)->toScale(self::SCALE);

        if (! $value->isPositive()) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }

        return new self($value);
    }

    public static function tryOf(mixed $rate): ?self
    {
        if (! is_string($rate)) {
            return null;
        }

        try {
            return self::of($rate);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * effective_rate = round_half_up(rate * (1 + markup_bps / 10000), 6)
     * (plan section 8.4). Computed as rate * (10000 + bps) / 10000 so the only
     * rounding happens in the final division.
     */
    public function withMarkup(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Markup basis points must not be negative.');
        }

        return new self(
            $this->value
                ->multipliedBy(10_000 + $basisPoints)
                ->dividedBy(10_000, self::SCALE, Rounding::MODE),
        );
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /**
     * Always six decimals, e.g. "17.250000", matching the column and the API.
     */
    public function toString(): string
    {
        return $this->value->toString();
    }

    public function equals(self $other): bool
    {
        return $this->value->isEqualTo($other->value);
    }
}
