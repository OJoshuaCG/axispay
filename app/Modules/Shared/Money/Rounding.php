<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use Brick\Math\RoundingMode;

/**
 * The single rounding rule of the platform (plan section 8.4): HALF_UP to the
 * target scale, applied exactly once, at the end of a calculation.
 */
final class Rounding
{
    public const RoundingMode MODE = RoundingMode::HalfUp;

    private function __construct() {}
}
