<?php

declare(strict_types=1);

namespace App\Modules\Access\Services;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;

/**
 * Plan 17.2: every tenant keeps at least one active owner. This is a domain
 * invariant about the `owner` role, not an authorization check.
 */
final class OwnerGuard
{
    public function isOwner(User $user): bool
    {
        return $user->roles->contains('name', SystemRole::Owner->value);
    }

    /**
     * True when $user is an owner and no other active owner exists in the
     * current tenant.
     */
    public function isLastActiveOwner(User $user): bool
    {
        if (! $this->isOwner($user)) {
            return false;
        }

        return ! User::role(SystemRole::Owner->value)
            ->whereKeyNot($user->getKey())
            ->whereNull('disabled_at')
            ->exists();
    }
}
