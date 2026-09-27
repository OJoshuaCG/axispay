<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral kind of an incoming gateway event (plan 14.3). The adapter
 * maps the provider's event type to it, so the event pipeline never matches
 * Stripe type strings. Every payment event is one kind: the handler re-reads
 * the payment and applies its current state (ADR-017), so the exact event
 * type never decides anything. Phase 7 adds the refund and dispute kinds.
 */
enum ProviderEventKind: string
{
    case AccountUpdated = 'account_updated';
    case AccountDeauthorized = 'account_deauthorized';
    case PaymentUpdated = 'payment_updated';
    case Unhandled = 'unhandled';
}
