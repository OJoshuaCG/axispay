<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Access\Services\OwnerGuard;
use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\CannotDeactivateUserException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Services\TenantLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deactivates a tenant user (plan 17.1 `users:manage`). The user can no longer
 * sign in or use an open session (canAccessPanel is checked on every panel
 * request). Requires a fresh re-authentication; the actor must hold every
 * permission of the target (an admin cannot deactivate an owner);
 * self-deactivation and removing the last owner are refused. The tenant row
 * is locked first so concurrent owner changes serialize.
 */
final readonly class DeactivateUser
{
    public function __construct(
        private OwnerGuard $owners,
        private RoleGrantGuard $grants,
        private ReauthenticationWindow $reauthentication,
        private TenantLock $tenantLock,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, User $target): User
    {
        Gate::forUser($actor)->authorize('deactivate', $target);

        if ($actor->is($target)) {
            throw CannotDeactivateUserException::self();
        }

        $this->reauthentication->ensureConfirmed();

        return DB::transaction(function () use ($actor, $target): User {
            $this->tenantLock->lock($target->tenant_id);
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->isDisabled()) {
                return $locked;
            }

            if (! $this->grants->canManage($actor, $locked)) {
                throw CannotDeactivateUserException::morePrivileged();
            }

            if ($this->owners->isLastActiveOwner($locked)) {
                throw CannotDeactivateUserException::lastOwner();
            }

            $locked->forceFill(['disabled_at' => now()])->save();
            $this->audit->record(AuditAction::UserDeactivated, $locked, actor: Actor::user($actor->id));

            return $locked;
        });
    }
}
