<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * A tenant-scoped query or write ran without a tenant context (plan 6.2).
 * This is fail-closed by design: it is a programming error, never a reason to
 * fall back to "all tenants".
 */
final class MissingTenantContextException extends LogicException
{
    public function __construct(?string $model = null)
    {
        parent::__construct($model === null
            ? 'No tenant context is set.'
            : "No tenant context is set for tenant-scoped model [{$model}].");
    }
}
