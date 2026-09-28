<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * No time is left in the payer's request for another gateway call
 * (CallBudget). Raised before a call starts, so nothing was sent.
 */
final class CallBudgetExhausted extends RuntimeException
{
    public static function before(string $operation): self
    {
        return new self("No time left in the request to {$operation}.");
    }
}
