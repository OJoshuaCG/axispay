<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Enums;

/**
 * Reasons the platform itself records when it cancels a link (plan 12.3.4,
 * 21.3).
 * People and integrators write their own free-text reason instead.
 */
enum CancelReason: string
{
    case GatewayDisconnected = 'gateway_disconnected';
    case TenantClosed = 'tenant_closed';
}
