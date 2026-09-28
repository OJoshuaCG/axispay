<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why an attempt is flagged "needs review" in the tenant panel (ADR-0051).
 */
enum ReviewReason: string
{
    /** Closed without the gateway (credentials lost): a card hold may remain. */
    case ClosedWithoutGateway = 'closed_without_gateway';

    /** The gateway reports a success on an attempt already closed. */
    case SucceededAfterClose = 'succeeded_after_close';
}
