<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The confirmation reached the gateway but its answer was lost (timeout,
 * unavailable): the payment may be under way. The payer is told it is
 * processing; events and the reconciliation settle it (ADR-0051).
 */
final class PaymentOutcomeUnknownException extends RuntimeException
{
    public static function after(Throwable $previous): self
    {
        return new self('The confirmation outcome is unknown.', 0, $previous);
    }
}
