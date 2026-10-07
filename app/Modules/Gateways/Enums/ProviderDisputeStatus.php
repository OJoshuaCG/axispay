<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral status of a gateway dispute (plan 7.5 `disputes.status`,
 * 16.2). Early inquiries (Stripe's `warning_*`) are mapped to the same open
 * statuses; `warning_closed` is an inquiry that closed without a chargeback.
 */
enum ProviderDisputeStatus: string
{
    case NeedsResponse = 'needs_response';
    case UnderReview = 'under_review';
    case Won = 'won';
    case Lost = 'lost';
    case WarningClosed = 'warning_closed';
}
