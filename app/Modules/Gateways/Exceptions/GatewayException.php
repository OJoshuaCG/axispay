<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base of the gateway errors seen by the domain. Messages are ours, generic
 * and safe to log: the provider's own message is never copied (it can echo a
 * masked key). `providerCode` and `providerRequestId` help support.
 */
abstract class GatewayException extends RuntimeException
{
    final public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
