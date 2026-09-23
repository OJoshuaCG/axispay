<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * `tenant_id` is immutable once a row exists (plan 6.2).
 */
final class TenantReassignmentException extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("The tenant of a [{$model}] cannot be changed.");
    }
}
