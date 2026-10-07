<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Fx\Models\FxQuote;
use App\Modules\Shared\Money\Money;

/**
 * What the checkout does about the currency once the card's country is known
 * (CheckoutConversion): charge `amount` (with the quote that converted it,
 * if any), or answer the payer instead (`answer`: ask for the currency
 * confirmation, or refuse because the merchant cannot convert). Nothing is
 * charged when there is an answer.
 */
final readonly class ChargePlan
{
    private function __construct(
        public ?Money $amount,
        public ?FxQuote $quote,
        public ?CheckoutResult $answer,
    ) {}

    public static function charge(Money $amount, ?FxQuote $quote = null): self
    {
        return new self($amount, $quote, null);
    }

    public static function answer(CheckoutResult $answer): self
    {
        return new self(null, null, $answer);
    }
}
