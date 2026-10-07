<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\Money;

/**
 * The amount and currency charged for a link and a card when NO conversion
 * applies (plan 13.2): the link's own amount. The conversion itself (card
 * country, quote, payer confirmation) is CheckoutConversion's, which asks
 * this class for every payment it does not convert. Resolved from the
 * container, so tests can replace it.
 */
class ChargeAmount
{
    public function for(PaymentLink $link, PaymentMethodPreview $card): Money
    {
        return $link->money();
    }
}
