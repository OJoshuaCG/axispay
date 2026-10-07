<?php

declare(strict_types=1);

namespace App\Modules\Fx\Enums;

/**
 * Why no quote can be produced for a conversion that is otherwise allowed.
 */
enum FxUnavailableReason: string
{
    /** `fixed` mode with neither a link rate nor a tenant rate. */
    case FixedRateMissing = 'fixed_rate_missing';

    /** `banxico_fix` mode without a usable FIX no more than the maximum age old (plan 13.3). */
    case NoFreshRate = 'no_fresh_rate';

    /** The converted amount does not reach the MXN minimum charge (plan 8.2). */
    case BelowMinimumAfterConversion = 'below_minimum_after_conversion';
}
