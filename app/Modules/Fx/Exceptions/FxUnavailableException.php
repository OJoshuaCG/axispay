<?php

declare(strict_types=1);

namespace App\Modules\Fx\Exceptions;

use App\Modules\Fx\Enums\FxUnavailableReason;
use RuntimeException;

/**
 * A conversion cannot be quoted right now (see FxUnavailableReason). The
 * checkout turns it into the payer-facing "conversion unavailable" answer;
 * link creation turns it into the matching API error.
 */
final class FxUnavailableException extends RuntimeException
{
    public function __construct(public readonly FxUnavailableReason $reason)
    {
        parent::__construct('Currency conversion is unavailable: '.$reason->value.'.');
    }
}
