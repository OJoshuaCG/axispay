<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use LogicException;

/**
 * A PaymentGateway method whose phase has not been implemented yet (payments
 * in Phase 4, refunds in Phase 7). Fails loudly instead of pretending.
 */
final class GatewayOperationNotImplementedException extends LogicException
{
    public static function for(string $operation, string $phase): self
    {
        return new self("PaymentGateway::{$operation}() is implemented in {$phase}.");
    }
}
