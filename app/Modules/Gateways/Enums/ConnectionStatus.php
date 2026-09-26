<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Status of a gateway connection (plan 7.4, 12.3.4). `active` is the only
 * status that lets a tenant charge; `disconnected` is terminal (a new
 * connection is created to reconnect).
 */
enum ConnectionStatus: string
{
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Restricted = 'restricted';
    case InvalidCredentials = 'invalid_credentials';
    case Disconnected = 'disconnected';

    public function canCharge(): bool
    {
        return $this === self::Active;
    }

    public function isDisconnected(): bool
    {
        return $this === self::Disconnected;
    }

    public function label(): string
    {
        return __('gateways.status.'.$this->value);
    }

    /** Filament color of the status badge (semantic palette names). */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Onboarding => 'info',
            self::Restricted => 'warning',
            self::InvalidCredentials => 'danger',
            self::Disconnected => 'gray',
        };
    }
}
