<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Supported payment gateways (ADR-019). Stripe only; the port is designed so
 * a redirect-based gateway can be added later.
 */
enum GatewayProvider: string
{
    case Stripe = 'stripe';

    public function label(): string
    {
        return __('gateways.provider.'.$this->value);
    }
}
