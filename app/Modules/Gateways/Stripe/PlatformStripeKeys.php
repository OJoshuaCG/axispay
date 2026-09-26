<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Exceptions\GatewayConfigurationException;

/**
 * The platform's own Stripe keys per mode (plan 12.2, config/services.php).
 * Test and live never mix (rules.md rule 4): a key of the wrong mode is
 * refused.
 */
final class PlatformStripeKeys
{
    public function secret(bool $livemode): string
    {
        return $this->value($livemode, 'secret', ['sk_', 'rk_']);
    }

    public function publishable(bool $livemode): string
    {
        return $this->value($livemode, 'publishable', ['pk_']);
    }

    public function connectWebhookSecret(bool $livemode): string
    {
        $secret = config('services.stripe.'.self::mode($livemode).'.connect_webhook_secret');

        if (! is_string($secret) || ! str_starts_with($secret, 'whsec_')) {
            throw new GatewayConfigurationException('The Stripe Connect webhook secret of the '.self::mode($livemode).' mode is not configured.');
        }

        return $secret;
    }

    public function apiVersion(): string
    {
        $version = config('services.stripe.api_version');

        if (! is_string($version) || $version === '') {
            throw new GatewayConfigurationException('services.stripe.api_version must be pinned.');
        }

        return $version;
    }

    public static function mode(bool $livemode): string
    {
        return $livemode ? 'live' : 'test';
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function value(bool $livemode, string $name, array $prefixes): string
    {
        $mode = self::mode($livemode);
        $value = config("services.stripe.{$mode}.{$name}");

        if (! is_string($value) || $value === '') {
            throw new GatewayConfigurationException("The Stripe {$name} key of the {$mode} mode is not configured.");
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix.$mode.'_')) {
                return $value;
            }
        }

        throw new GatewayConfigurationException("The Stripe {$name} key configured for the {$mode} mode is not a {$mode} key.");
    }
}
