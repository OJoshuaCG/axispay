<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Gateways\Sandbox\SandboxPaymentGateway;
use App\Modules\Gateways\Stripe\StripeGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Picks the adapter for a provider (plan 12.1, ADR-019): a plain `match`, no
 * plugins. Tests swap adapters with fake() (FakePaymentGateway). With the
 * checkout sandbox on (local and testing only, ADR-0051) Stripe is served by
 * SandboxPaymentGateway.
 */
final class GatewayFactory
{
    /** @var array<string, PaymentGateway> */
    private array $overrides = [];

    public function __construct(private readonly Container $container) {}

    public function for(GatewayProvider $provider): PaymentGateway
    {
        if (isset($this->overrides[$provider->value])) {
            return $this->overrides[$provider->value];
        }

        return match ($provider) {
            GatewayProvider::Stripe => SandboxMode::enabled()
                ? $this->container->make(SandboxPaymentGateway::class)
                : $this->container->make(StripeGateway::class),
        };
    }

    /** Test seam: serve this gateway for its provider. */
    public function fake(PaymentGateway $gateway): void
    {
        $this->overrides[$gateway->provider()->value] = $gateway;
    }
}
