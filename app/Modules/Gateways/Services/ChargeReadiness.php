<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Models\GatewayConnection;

/**
 * Whether the current tenant can charge in the current mode (plan 10.5
 * business validation 2, 12.3.4, 21.3): its connection in this mode is
 * `active` and the gateway reports charges enabled. Used before creating a
 * link; answers from our database (ADR-017), never by calling the gateway.
 */
final class ChargeReadiness
{
    public function canChargeInCurrentMode(): bool
    {
        return self::ready(GatewayConnection::query()->current()->first());
    }

    /**
     * The same check inside the caller's transaction, holding a shared lock
     * on the connection row until that transaction ends. A disconnection
     * takes an exclusive lock on the same row, so it either committed
     * before (and this answers false) or waits until the caller committed
     * (and then sees, and cancels, what the caller created).
     */
    public function canChargeInCurrentModeLocked(): bool
    {
        return self::ready(GatewayConnection::query()->current()->sharedLock()->first());
    }

    /**
     * Whether a connection that is not disconnected exists in the current
     * mode, whatever its state (a restricted account keeps its links, plan
     * 12.3.4).
     */
    public function hasConnectionInCurrentMode(): bool
    {
        return GatewayConnection::query()->current()->exists();
    }

    /** Whether this connection can charge now: `active` and charges enabled at the gateway. */
    public function isReady(?GatewayConnection $connection): bool
    {
        return self::ready($connection);
    }

    private static function ready(?GatewayConnection $connection): bool
    {
        return $connection !== null && $connection->status->canCharge() && $connection->charges_enabled;
    }
}
