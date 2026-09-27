<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral status of a gateway payment (plan 9.2, 12.1). The adapter
 * maps its own statuses to these; the payments domain never sees gateway
 * status strings. `RequiresCapture` is the authorized-not-captured stage of
 * the linear flow (ADR-0050).
 */
enum ProviderPaymentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case RequiresCapture = 'requires_capture';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';
}
