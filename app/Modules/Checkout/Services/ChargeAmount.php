<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\Money;

/**
 * The amount and currency actually charged for a link and a card (plan 13.2).
 * Phase 4 has no currency conversion: always the link's own amount. Phase 6
 * plugs the conversion policy (card country, quote, payer confirmation) in
 * here (the class is resolved from the container, so tests and Phase 6
 * can replace it).
 */
class ChargeAmount
{
    public function for(PaymentLink $link, PaymentMethodPreview $card): Money
    {
        return $link->money();
    }
}
