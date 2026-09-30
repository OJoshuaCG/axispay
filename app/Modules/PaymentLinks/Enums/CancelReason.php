<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Enums;

/**
 * Reasons the platform itself records when it cancels a link (plan 12.3.4,
 * 21.3, 15.8.4).
 * People and integrators write their own free-text reason instead.
 */
enum CancelReason: string
{
    case GatewayDisconnected = 'gateway_disconnected';
    case TenantClosed = 'tenant_closed';
    /** The merchant rejected a payment with `cancel_link: true` (plan 15.8.4). */
    case RejectedByMerchant = 'rejected_by_merchant';
}
