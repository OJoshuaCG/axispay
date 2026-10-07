<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral kind of an incoming gateway event (plan 14.3). The adapter
 * maps the provider's event type to it, so the event pipeline never matches
 * Stripe type strings. Every payment event is one kind: the handler re-reads
 * the payment and applies its current state (ADR-017), so the exact event
 * type never decides anything. The refund and dispute kinds (Phase 7) are
 * re-read the same way: `RefundUpdated` names one refund, `PaymentRefundsChanged`
 * says the refunds of a payment changed (Stripe's `charge.refunded`, which also
 * carries refunds made in the gateway's own dashboard) and `DisputeUpdated`
 * names one dispute.
 */
enum ProviderEventKind: string
{
    case AccountUpdated = 'account_updated';
    case AccountDeauthorized = 'account_deauthorized';
    case PaymentUpdated = 'payment_updated';
    case RefundUpdated = 'refund_updated';
    case PaymentRefundsChanged = 'payment_refunds_changed';
    case DisputeUpdated = 'dispute_updated';
    case Unhandled = 'unhandled';
}
