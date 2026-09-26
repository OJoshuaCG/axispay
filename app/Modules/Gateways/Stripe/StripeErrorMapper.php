<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;

/**
 * Maps stripe-php exceptions to the gateway errors of the port (plan 12.6).
 *
 * The decision uses the HTTP status first: Stripe documents a missing
 * restricted-key permission both as a 403 permission error and as an
 * "invalid request error" (ADR-0047), so any 401/403 is treated as an
 * access problem whatever the error type.
 *
 * Neither Stripe's message nor the Stripe exception itself is kept: an
 * authentication error echoes a masked key, and the SDK exception's trace
 * holds the API key as a call argument (plan 26.2 case 19). Only the error
 * code, the request ID and the HTTP status travel on.
 */
final class StripeErrorMapper
{
    public static function map(ApiErrorException $e, string $operation): GatewayException
    {
        $status = $e->getHttpStatus();
        $code = $e->getStripeCode();
        $requestId = $e->getRequestId();

        return match (true) {
            $e instanceof AuthenticationException || $status === 401 => new GatewayAuthenticationException(
                "Stripe rejected the credentials during {$operation}.", $code, $requestId, 401,
            ),
            $e instanceof PermissionException || $status === 403 => new GatewayAuthenticationException(
                "Stripe denied access during {$operation}.", $code, $requestId, 403,
            ),
            $e instanceof RateLimitException || $e instanceof ApiConnectionException || $status === null || $status === 429 || $status >= 500 => new GatewayUnavailableException(
                "Stripe is unavailable during {$operation}.", $code, $requestId, $status,
            ),
            default => new GatewayRequestException(
                "Stripe refused the request during {$operation}.", $code, $requestId, $status,
            ),
        };
    }
}
