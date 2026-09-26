<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Why a connection ended (`gateway_connections.disconnect_reason`).
 */
enum DisconnectReason: string
{
    /** A tenant user disconnected it from the panel. */
    case UserRequested = 'user_requested';

    /** The merchant removed the platform from their gateway account. */
    case Deauthorized = 'deauthorized';

    public function label(): string
    {
        return __('gateways.disconnect_reason.'.$this->value);
    }
}
