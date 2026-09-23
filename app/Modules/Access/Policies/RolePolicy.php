<?php

declare(strict_types=1);

namespace App\Modules\Access\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * Roles are permission sets editable only by the superadmin (plan 17.2);
 * tenants can read them.
 */
final class RolePolicy
{
    public function viewAny(User|PlatformAdmin $actor): bool
    {
        return $actor instanceof PlatformAdmin || $actor->checkPermissionTo(TenantPermission::UsersManage->value);
    }

    public function view(User|PlatformAdmin $actor, Role $role): bool
    {
        if ($actor instanceof PlatformAdmin) {
            return true;
        }

        return ($role->team_id === null || $role->team_id === $actor->tenant_id)
            && $actor->checkPermissionTo(TenantPermission::UsersManage->value);
    }

    public function create(User|PlatformAdmin $actor): bool
    {
        return $actor instanceof PlatformAdmin && $actor->isSuperadmin();
    }

    public function update(User|PlatformAdmin $actor, Role $role): bool
    {
        return $actor instanceof PlatformAdmin && $actor->isSuperadmin();
    }

    public function delete(User|PlatformAdmin $actor, Role $role): bool
    {
        return false;
    }
}
