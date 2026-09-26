<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * How a tenant connects its gateway account (ADR-004). The payments domain
 * never branches on it: only the StripeClientFactory and the connection
 * flows do.
 */
enum ConnectionMethod: string
{
    case PlatformOnboarding = 'platform_onboarding';
    case OAuth = 'oauth';
    case ApiKey = 'api_key';

    /** Enabled by the platform configuration (plan 12.3). */
    public function isEnabled(): bool
    {
        return config('axispay.gateways.stripe.connection_methods.'.$this->value) === true;
    }

    /** Connect methods receive events on the platform's Connect endpoint. */
    public function usesConnect(): bool
    {
        return $this !== self::ApiKey;
    }

    public function label(): string
    {
        return __('gateways.method.'.$this->value);
    }
}
