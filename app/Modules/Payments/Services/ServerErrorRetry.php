<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * A gateway call that answered a server error (5xx) under an idempotency
 * key keeps answering that same error for that key: Stripe stores the
 * result of a request that started executing, 500s included, for at least
 * 24 hours. Retrying with the same key can therefore never succeed.
 *
 * After a 5xx the payment is re-read: if it moved on (captured, voided,
 * canceled elsewhere), that state is returned; if it is still where the call
 * found it, the call is repeated under a derived key (`:r1`, `:r2`), at most
 * MAX_RETRIES times, and logged. The derived keys are deterministic, so a
 * later process replays the same results instead of repeating the work, and
 * the gateway's own state rules (a payment is captured or voided once) keep
 * a retry from acting twice (ADR-0051).
 */
final class ServerErrorRetry
{
    public const int MAX_RETRIES = 2;

    /**
     * @template T
     *
     * @param  Closure(string): T  $call  the gateway call under the given key
     * @param  Closure(): (ProviderPayment|null)  $reread  the payment if it moved on, null if the call is still due
     * @param  array<string, mixed>  $context  log context (our identifiers only)
     * @return T|ProviderPayment
     */
    public static function run(string $key, Closure $call, Closure $reread, array $context): mixed
    {
        $current = $key;

        for ($retry = 1; ; $retry++) {
            try {
                return $call($current);
            } catch (GatewayUnavailableException $e) {
                if (($e->httpStatus ?? 0) < 500 || $retry > self::MAX_RETRIES) {
                    throw $e;
                }

                $moved = $reread();

                if ($moved !== null) {
                    return $moved;
                }

                Log::warning('A gateway call answered a server error; repeated under a new idempotency key.', [...$context, 'retry' => $retry]);
                $current = $key.':r'.$retry;
            }
        }
    }
}
