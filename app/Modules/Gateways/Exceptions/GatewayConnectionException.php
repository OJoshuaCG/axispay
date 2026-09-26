<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use App\Modules\Gateways\Enums\ConnectionError;
use RuntimeException;
use Throwable;

/**
 * A connection action cannot run in the current state (method disabled,
 * already connected, country not allowed...). Safe to show: the panel uses
 * `userMessage()`.
 */
final class GatewayConnectionException extends RuntimeException
{
    public function __construct(public readonly ConnectionError $error, ?Throwable $previous = null)
    {
        parent::__construct('Gateway connection refused: '.$error->value, 0, $previous);
    }

    public function userMessage(): string
    {
        return $this->error->message();
    }
}
