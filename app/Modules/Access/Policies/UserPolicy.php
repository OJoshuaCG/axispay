<?php

declare(strict_types=1);

namespace App\Modules\Access\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * Tenant user management (plan 17.1 `users:manage`). Checks permissions only,
 * never role names (ADR-014). Users join through invitations, so there is no
 * direct create/update/delete. Record-level checks rely on the tenant scope:
 * a user of another tenant is never even loaded (404, plan 6.6).
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::UsersManage->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $this->manages($actor, $user);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, User $user): bool
    {
        return false;
    }

    public function delete(User $actor, User $user): bool
    {
        return false;
    }

    public function invite(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::UsersManage->value);
    }

    public function assignRoles(User $actor, User $user): bool
    {
        return $this->manages($actor, $user) && ! $actor->is($user);
    }

    public function deactivate(User $actor, User $user): bool
    {
        return $this->manages($actor, $user) && ! $actor->is($user) && ! $user->isDisabled()
            && app(RoleGrantGuard::class)->canManage($actor, $user);
    }

    public function reactivate(User $actor, User $user): bool
    {
        return $this->manages($actor, $user) && $user->isDisabled()
            && app(RoleGrantGuard::class)->canManage($actor, $user);
    }

    /** Plan 17.4: only superadmins may impersonate. */
    public function impersonate(PlatformAdmin $admin, User $user): bool
    {
        return $admin->isSuperadmin() && $admin->disabled_at === null;
    }

    private function manages(User $actor, User $user): bool
    {
        return $actor->tenant_id === $user->tenant_id
            && $actor->checkPermissionTo(TenantPermission::UsersManage->value);
    }
}
