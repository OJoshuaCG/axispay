<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Exceptions;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use LogicException;

/**
 * A link transition outside the table of plan 9.1. A bug, not a client
 * error: callers check the state first and answer with the matching API
 * error (e.g. `link_not_cancelable`).
 */
final class InvalidStateTransition extends LogicException
{
    public function __construct(
        public readonly PaymentLinkStatus $from,
        public readonly PaymentLinkStatus $to,
        string $reason = 'not allowed by the link state machine',
    ) {
        parent::__construct("Payment link transition {$from->value} -> {$to->value} {$reason}.");
    }
}
