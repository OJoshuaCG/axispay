<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why a link's attempt could not be claimed for a new confirmation
 * (ClaimLinkAttempt).
 */
enum ClaimRefusal: string
{
    case AlreadyPaid = 'already_paid';

    /** Another confirmation or a payment under way holds the link. */
    case InProgress = 'in_progress';

    /** The link passed its expiry. */
    case Expired = 'expired';
}
