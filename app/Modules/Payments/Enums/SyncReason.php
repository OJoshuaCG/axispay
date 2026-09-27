<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** Why an attempt is re-read from the gateway. */
enum SyncReason: string
{
    /** A gateway event arrived (plan 14.2): re-read and apply, capture if authorized. */
    case Webhook = 'webhook';
    /** The payment page asked (return from 3D Secure, status polling). */
    case Checkout = 'checkout';
    /** The periodic safety net (plan 12.5): also voids stale authorizations. */
    case Reconciliation = 'reconciliation';
}
