<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Business events recorded for the outgoing webhooks (plan 15.2). Phase 4
 * records them; Phase 5 delivers them; Phase 7 adds the refund and dispute
 * events (ADR-0066). `data` is a snapshot frozen when the
 * event is recorded (plan 15.3): the objects as the API shows them then.
 */
enum DomainEventType: string
{
    case PaymentLinkOpened = 'payment_link.opened';
    case PaymentLinkPaid = 'payment_link.paid';
    case PaymentProcessing = 'payment.processing';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case PaymentCanceled = 'payment.canceled';
    case RefundCreated = 'refund.created';
    case RefundSucceeded = 'refund.succeeded';
    case RefundFailed = 'refund.failed';
    case DisputeCreated = 'dispute.created';
    case DisputeClosed = 'dispute.closed';
}
