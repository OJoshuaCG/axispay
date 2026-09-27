<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/** Another process holds the attempt's lease; retry later (queued jobs do). */
final class AttemptBusyException extends RuntimeException
{
    public static function for(string $attemptId): self
    {
        return new self("Payment attempt {$attemptId} is being worked on by another process.");
    }
}
