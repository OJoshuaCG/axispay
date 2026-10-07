<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Data;

use App\Modules\Shared\Money\Money;

/**
 * One line of the merchant's breakdown of a payment link (ADR-0064): a label
 * and an amount in the link's currency, for display only. At most one line
 * `absorbsRounding`: when the link is converted to MXN, that line takes the
 * residual so the converted lines add up to the amount charged.
 */
final readonly class LineItem
{
    public function __construct(
        public string $label,
        public Money $amount,
        public bool $absorbsRounding = false,
    ) {}
}
