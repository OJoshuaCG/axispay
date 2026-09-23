<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Row lock on the tenant, taken FIRST inside a transaction by actions that
 * check a tenant-wide invariant (e.g. "at least one active owner"). A single
 * lock order (tenant, then the rows involved) avoids deadlocks and makes
 * concurrent changes wait for each other.
 */
final class TenantLock
{
    public function lock(string $tenantId): Tenant
    {
        return Tenant::query()->lockForUpdate()->findOrFail($tenantId);
    }
}
