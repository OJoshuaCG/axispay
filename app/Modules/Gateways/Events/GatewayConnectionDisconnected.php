<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Events;

/**
 * A connection became `disconnected` (plan 12.3.4). Dispatched after the
 * change is committed; the PaymentLinks module cancels the active links of
 * that tenant and mode.
 */
final readonly class GatewayConnectionDisconnected
{
    public function __construct(
        public string $connectionId,
        public string $tenantId,
        public bool $livemode,
    ) {}
}
