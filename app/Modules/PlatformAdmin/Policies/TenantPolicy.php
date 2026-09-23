<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Policies;

use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Tenants are managed from the admin panel only. `support_readonly` can look;
 * only `superadmin` creates tenants and changes their status (plan 17.4, 21.3).
 */
final class TenantPolicy
{
    public function viewAny(PlatformAdmin $admin): bool
    {
        return true;
    }

    public function view(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function create(PlatformAdmin $admin): bool
    {
        return $admin->isSuperadmin();
    }

    public function update(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return false;
    }

    public function changeStatus(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return $admin->isSuperadmin() && $tenant->status->allowedTransitions() !== [];
    }

    public function delete(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return false;
    }

    public function deleteAny(PlatformAdmin $admin): bool
    {
        return false;
    }
}
