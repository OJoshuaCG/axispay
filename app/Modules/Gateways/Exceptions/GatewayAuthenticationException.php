<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

/**
 * The gateway rejected the credentials (401) or refused access to the
 * account (403): an invalid or revoked api_key, a key that lost a
 * permission, or a Connect account that removed the platform (plan 12.6).
 */
final class GatewayAuthenticationException extends GatewayException
{
    public function isPermissionDenied(): bool
    {
        return $this->httpStatus === 403;
    }
}
