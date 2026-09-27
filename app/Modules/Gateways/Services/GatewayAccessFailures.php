<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Actions\MarkConnectionCredentialsInvalid;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Models\GatewayConnection;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Plan 12.6 on the payment paths (retrieve, create, confirm, capture, void):
 * when the gateway rejects the credentials or denies access,
 *
 *  - api_key: the connection becomes `invalid_credentials` at once (with the
 *    stale-credential guard: only if the key that failed is still the
 *    stored one), which stops link creation and notifies the tenant;
 *  - Connect methods: the connection is flagged for review with an
 *    alert-level log line (the platform key or the account's access broke).
 *
 * The exception is rethrown; queued callers fail at once instead of
 * retrying a call that cannot succeed.
 */
final readonly class GatewayAccessFailures
{
    public function __construct(private MarkConnectionCredentialsInvalid $markInvalid) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws GatewayAuthenticationException
     */
    public function guard(GatewayConnection $connection, Closure $call): mixed
    {
        // The key the call is about to use (the guard compares it later).
        $fingerprint = $connection->credentials_fingerprint;

        try {
            return $call();
        } catch (GatewayAuthenticationException $e) {
            if ($connection->isApiKey()) {
                $this->markInvalid->handle($connection, $fingerprint);
            } else {
                Log::alert('The gateway denied access to a Connect account on a payment call; the connection needs review.', [
                    'gateway_connection_id' => $connection->id,
                    'http_status' => $e->httpStatus,
                    'provider_request_id' => $e->providerRequestId,
                ]);
            }

            throw $e;
        }
    }
}
