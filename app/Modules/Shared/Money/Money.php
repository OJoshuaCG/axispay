<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;

/**
 * Immutable monetary amount: an integer number of minor units plus a supported
 * currency (ADR-007). This is what the domain and the database (BIGINT +
 * CHAR(3)) use. Arithmetic is delegated to brick/money / brick/math; no float
 * is ever involved.
 */
final readonly class Money
{
    private function __construct(
        public int $minorAmount,
        public CurrencyCode $currency,
    ) {}

    public static function ofMinor(int $minorAmount, CurrencyCode $currency): self
    {
        return new self($minorAmount, $currency);
    }

    public static function zero(CurrencyCode $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Builds from a brick Money whose scale matches the currency exponent.
     * Throws instead of rounding: rounding must be explicit at the call site.
     */
    public static function fromBrick(BrickMoney $money): self
    {
        $currency = CurrencyCode::tryFrom($money->getCurrency()->getCurrencyCode())
            ?? throw new InvalidArgumentException("Unsupported currency {$money->getCurrency()->getCurrencyCode()}.");

        if ($money->getAmount()->getScale() !== $currency->exponent()) {
            throw new InvalidArgumentException('The amount scale does not match the currency exponent.');
        }

        return new self($money->getMinorAmount()->toInt(), $currency);
    }

    public function toBrick(): BrickMoney
    {
        return BrickMoney::ofMinor($this->minorAmount, $this->currency->value);
    }

    /**
     * Exact decimal string with as many decimals as the currency exponent,
     * e.g. "150.50" (plan section 8.3).
     */
    public function toDecimalString(): string
    {
        return $this->toBrick()->getAmount()->toString();
    }

    /**
     * API representation (plan section 8.3).
     *
     * @return array{amount: string, amount_minor: int, currency: string}
     */
    public function toApiArray(): array
    {
        return [
            'amount' => $this->toDecimalString(),
            'amount_minor' => $this->minorAmount,
            'currency' => $this->currency->value,
        ];
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            BigDecimal::of($this->minorAmount)->plus($other->minorAmount)->toInt(),
            $this->currency,
        );
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            BigDecimal::of($this->minorAmount)->minus($other->minorAmount)->toInt(),
            $this->currency,
        );
    }

    /**
     * Percentage in basis points, rounded HALF_UP once to minor units:
     * round_half_up(amount_minor * bps / 10000) (plan section 8.4).
     */
    public function percentage(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Basis points must not be negative.');
        }

        $minor = BigDecimal::of($this->minorAmount)
            ->multipliedBy($basisPoints)
            ->dividedBy(10_000, 0, Rounding::MODE)
            ->toInt();

        return new self($minor, $this->currency);
    }

    /**
     * Converts with an (already effective) exchange rate, rounding HALF_UP once
     * at the end: round_half_up(original_minor * effective_rate), adjusted for
     * the exponent difference between the two currencies (plan section 8.4).
     */
    public function convertTo(CurrencyCode $target, ExchangeRate $rate): self
    {
        $exponentShift = $target->exponent() - $this->currency->exponent();

        $minor = BigDecimal::of($this->minorAmount)
            ->multipliedBy($rate->toBigDecimal())
            ->withPointMovedRight($exponentShift)
            ->toScale(0, Rounding::MODE)
            ->toInt();

        return new self($minor, $target);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorAmount === $other->minorAmount;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency->value} vs {$other->currency->value}.",
            );
        }
    }
}
