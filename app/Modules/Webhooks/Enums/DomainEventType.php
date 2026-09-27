<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Business events recorded for the outgoing webhooks (plan 15.2). Phase 4
 * records them; Phase 5 delivers them.
 */
enum DomainEventType: string
{
    case PaymentLinkOpened = 'payment_link.opened';
    case PaymentLinkPaid = 'payment_link.paid';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
}
