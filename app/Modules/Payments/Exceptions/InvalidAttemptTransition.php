<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use LogicException;

/** An attempt transition outside plan 9.2 (a bug, never a payer error). */
final class InvalidAttemptTransition extends LogicException
{
    public function __construct(
        public readonly PaymentAttemptStatus $from,
        public readonly PaymentAttemptStatus $to,
        string $reason = 'not allowed by the attempt state machine',
    ) {
        parent::__construct("Payment attempt transition {$from->value} -> {$to->value} {$reason}.");
    }
}
