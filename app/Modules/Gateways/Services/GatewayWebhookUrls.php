<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Shared\Http\Errors\ApiSurface;

/**
 * Public URLs of the incoming webhook endpoints (plan 14.1), always HTTPS on
 * the API host. AXISPAY_STRIPE_WEBHOOK_BASE_URL overrides the base (a tunnel
 * in local development, where Stripe cannot reach *.localhost).
 */
final class GatewayWebhookUrls
{
    public function direct(GatewayConnection $connection): string
    {
        return $this->base().'/webhooks/stripe/direct/'.$connection->id;
    }

    public function connect(bool $livemode): string
    {
        return $this->base().'/webhooks/stripe/connect/'.($livemode ? 'live' : 'test');
    }

    private function base(): string
    {
        $override = config('axispay.gateways.stripe.webhook_base_url');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        return 'https://'.ApiSurface::host();
    }
}
