<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * A row was created with an explicit `tenant_id` that differs from the active
 * tenant context. Writing into another tenant is never allowed from inside a
 * tenant context; use TenantContext::runAsTenant() for the target tenant.
 */
final class TenantMismatchException extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("A [{$model}] cannot be written for a tenant other than the current tenant context.");
    }
}
