<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Enums;

/** Which page the payer sees for a link (plan 11.2). */
enum CheckoutState: string
{
    case Active = 'active';
    case Processing = 'processing';
    case Paid = 'paid';
    case Expired = 'expired';
    case Canceled = 'canceled';
    /** Long card-testing block (plan 11.7 rule 4) or no gateway able to charge. */
    case Unavailable = 'unavailable';
}
