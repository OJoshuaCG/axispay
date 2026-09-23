<?php

declare(strict_types=1);

namespace App\Modules\Access\Services;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * No privilege escalation (plan 17.2, ADR-0032): an actor may only grant a
 * role, or act on a user, whose permissions it holds itself. This is how
 * "admin: everything except gateway:manage and transferring ownership" is
 * enforced with permissions only. Used by ChangeUserRoles, InviteUser,
 * AcceptInvitation (re-check against the inviter) and Deactivate/Reactivate.
 *
 * Runs in the current tenant context (permission team = tenant).
 */
final class RoleGrantGuard
{
    public function canGrant(User $actor, SystemRole $role): bool
    {
        if ($actor->isDisabled()) {
            return false;
        }

        foreach ($role->permissions() as $permission) {
            if (! $actor->checkPermissionTo($permission->value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<SystemRole>
     */
    public function grantableRoles(User $actor): array
    {
        return array_values(array_filter(SystemRole::cases(), fn (SystemRole $role): bool => $this->canGrant($actor, $role)));
    }

    /**
     * @return array<string, string> value => label, for form options
     */
    public function grantableRoleOptions(User $actor): array
    {
        $options = [];

        foreach ($this->grantableRoles($actor) as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * True when $actor holds every permission $target has (a user cannot
     * deactivate or reactivate someone more privileged than themselves).
     */
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->isDisabled()) {
            return false;
        }

        foreach ($target->getAllPermissions() as $permission) {
            $name = $permission instanceof Model ? $permission->getAttribute('name') : null;

            if (! is_string($name) || ! $actor->checkPermissionTo($name)) {
                return false;
            }
        }

        return true;
    }
}
