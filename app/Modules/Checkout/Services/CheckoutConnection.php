<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\ChargeReadiness;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The current tenant's connection in the current mode, read once per
 * checkout request (page, status, pay all need it), and whether it can
 * charge (ChargeReadiness). Remembered on the request itself, so nothing
 * outlives it.
 */
final readonly class CheckoutConnection
{
    public function __construct(
        private Request $request,
        private TenantContext $context,
        private ChargeReadiness $readiness,
    ) {}

    public function current(): ?GatewayConnection
    {
        $key = 'axispay.checkout.connection:'.$this->context->idOrFail().':'.($this->context->livemode() ? '1' : '0');

        if (! $this->request->attributes->has($key)) {
            $this->request->attributes->set($key, GatewayConnection::query()->current()->first());
        }

        $connection = $this->request->attributes->get($key);

        return $connection instanceof GatewayConnection ? $connection : null;
    }

    /** The current connection when it can charge now, otherwise null. */
    public function chargeable(): ?GatewayConnection
    {
        $connection = $this->current();

        return $this->readiness->isReady($connection) ? $connection : null;
    }
}
