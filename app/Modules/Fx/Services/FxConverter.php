<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;

/**
 * The only place the USD to MXN arithmetic happens (plan 8.4):
 * converted = round_half_up(original_minor * effective_rate), rounded once.
 * The effective rate already carries the markup (ExchangeRate::withMarkup),
 * so a quote can be reproduced from its stored rate alone.
 */
final class FxConverter
{
    public const CurrencyCode TARGET = CurrencyCode::MXN;

    public function apply(Money $original, ExchangeRate $effectiveRate): Money
    {
        return $original->convertTo(self::TARGET, $effectiveRate);
    }
}
