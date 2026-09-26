<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral kind of an incoming gateway event (plan 14.3). The adapter
 * maps the provider's event type to it, so the event pipeline never matches
 * Stripe type strings. Phase 4+ adds the payment, refund and dispute kinds.
 */
enum ProviderEventKind: string
{
    case AccountUpdated = 'account_updated';
    case AccountDeauthorized = 'account_deauthorized';
    case Unhandled = 'unhandled';
}
