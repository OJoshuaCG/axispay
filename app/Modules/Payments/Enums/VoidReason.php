<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why an attempt's gateway payment is voided or closed (ADR-0050, ADR-0051),
 * as recorded in the audit log.
 */
enum VoidReason: string
{
    /** The merchant's pre-payment validation rejected it (or `fail_closed`, Phase 5). */
    case MerchantRejected = 'merchant_rejected';

    /** The authorization was not captured within the capture window. */
    case CaptureWindowElapsed = 'capture_window_elapsed';

    /** The link expired, was canceled or its tenant closed. */
    case LinkClosed = 'link_closed';

    /** A 3D Secure step left unanswered by the payer. */
    case AbandonedAction = 'abandoned_action';
}
