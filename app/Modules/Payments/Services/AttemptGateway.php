<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
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

    /**
     * A guarded gateway call under an idempotency key that is repeated under
     * a derived key after a stored server error, unless the payment moved on
     * (ServerErrorRetry).
     *
     * @template T
     *
     * @param  Closure(string): T  $call  the call under the given key
     * @param  Closure(): (ProviderPayment|null)  $moved  the payment if it moved on, null if the call is still due
     * @param  array<string, mixed>  $context  log context (our identifiers only)
     * @return T|ProviderPayment
     */
    public function retryingCall(GatewayConnection $connection, string $key, Closure $call, Closure $moved, array $context): mixed
    {
        return ServerErrorRetry::run($key, fn (string $current): mixed => $this->guard($connection, static fn (): mixed => $call($current)), $moved, $context);
    }

    /**
     * The payment as the gateway reports it now, when it is no longer in one
     * of `$stillDue` (for retryingCall()); null while the call is still due.
     *
     * @param  list<ProviderPaymentStatus>  $stillDue
     */
    public function movedOn(PaymentGateway $gateway, GatewayConnection $connection, string $providerPaymentId, array $stillDue): ?ProviderPayment
    {
        $now = $this->guard($connection, static fn (): ProviderPayment => $gateway->retrievePayment($connection, $providerPaymentId));

        return in_array($now->status, $stillDue, true) ? null : $now;
    }
}
