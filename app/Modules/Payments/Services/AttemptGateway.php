<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayAccessFailures;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\Payments\Models\PaymentAttempt;
use Closure;

/**
 * The gateway and the connection an attempt was created with. Every later
 * call (retrieve, capture, void, refunds) uses that same connection, even if
 * the tenant connected another account since (plan 12.3.4).
 */
final readonly class AttemptGateway
{
    public function __construct(
        private GatewayFactory $gateways,
        private GatewayAccessFailures $failures,
    ) {}

    /**
     * @return array{0: PaymentGateway, 1: GatewayConnection}
     */
    public function for(PaymentAttempt $attempt): array
    {
        $connection = GatewayConnection::query()->findOrFail($attempt->gateway_connection_id);

        return [$this->gateways->for($attempt->provider), $connection];
    }

    /**
     * Runs a gateway call for the connection; rejected credentials are
     * handled as plan 12.6 says (GatewayAccessFailures) and rethrown.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function guard(GatewayConnection $connection, Closure $call): mixed
    {
        return $this->failures->guard($connection, $call);
    }
}
