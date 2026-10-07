<?php

declare(strict_types=1);

namespace App\Modules\Fx\Enums;

/**
 * Why a payment that needs a conversion cannot be charged (plan 13.2): the
 * checkout shows the payer "this merchant cannot charge this amount in USD
 * to Mexican cards" and records `conversion_unavailable` for the tenant.
 */
enum ConversionBlockReason: string
{
    /** The tenant has conversion off, or the link opted out (`fx.mode = none`). */
    case ConversionDisabled = 'conversion_disabled';
}
