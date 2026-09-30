<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Exceptions;

use App\Modules\Shared\Contracts\UserFacingError;
use App\Modules\Webhooks\Enums\UnsafeDestinationReason;
use RuntimeException;

/**
 * A merchant URL refused by the SSRF protection (plan 15.7). The message
 * never contains the URL or the addresses it resolved to.
 */
final class UnsafeDestinationException extends RuntimeException implements UserFacingError
{
    public function __construct(public readonly UnsafeDestinationReason $reason)
    {
        parent::__construct("Destination refused: {$reason->value}.");
    }

    public function userMessage(): string
    {
        return $this->reason->message();
    }
}
