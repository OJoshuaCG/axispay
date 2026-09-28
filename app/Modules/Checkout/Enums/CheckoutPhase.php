<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Enums;

/**
 * What the page says while a payment is under way (plan 11.5): the gateway
 * is still processing it, or the order is being checked with the merchant
 * (an authorization waiting for capture, ADR-0050).
 */
enum CheckoutPhase: string
{
    case Processing = 'processing';
    case Validating = 'validating';
}
