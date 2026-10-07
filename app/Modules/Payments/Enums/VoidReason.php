<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why an attempt's gateway payment is voided or closed (ADR-0050, ADR-0051),
 * as recorded in the audit log and sent in `payment.canceled` (ADR-0062).
 */
enum VoidReason: string
{
    /** The merchant's pre-payment validation rejected it. */
    case MerchantRejected = 'merchant_rejected';

    /** The pre-payment validation failed and the merchant's policy is `fail_closed` (plan 15.8.5). */
    case ValidationFailed = 'validation_failed';

    /** The authorization was not captured within the capture window. */
    case CaptureWindowElapsed = 'capture_window_elapsed';

    /** The link expired, was canceled or its tenant closed. */
    case LinkClosed = 'link_closed';

    /** The integrator asked for it: `POST /v1/payments/{id}/void` (ADR-0066). */
    case MerchantRequested = 'merchant_requested';

    /** A 3D Secure step left unanswered by the payer. */
    case AbandonedAction = 'abandoned_action';

    /**
     * The gateway released the payment on its own, outside any void of ours
     * (an authorization that expired there, a capture it refused, a
     * cancellation reported by its webhook). Only ever sent in
     * `payment.canceled`: nothing in this system asked for it.
     */
    case GatewayCanceled = 'gateway_canceled';
}
