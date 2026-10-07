<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral status of a gateway refund (plan 16.1). The adapter maps
 * its own statuses to these; a refund that still needs something from the
 * gateway (Stripe's `requires_action`) is `pending`. `succeeded`, `failed` and
 * `canceled` are final, except that a refund the gateway reported as
 * `succeeded` can still fail later (the card network returns it).
 */
enum ProviderRefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
