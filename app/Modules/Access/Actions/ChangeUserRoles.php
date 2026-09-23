<?php

declare(strict_types=1);

namespace App\Modules\Access\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Exceptions\RoleChangeNotAllowedException;
use App\Modules\Access\Notifications\SensitiveRoleAssignedNotification;
use App\Modules\Access\Services\OwnerGuard;
use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantLock;
use App\Modules\Tenancy\Services\TenantOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Replaces a user's roles (plan 17.2, 17.3):
 *
 *  - needs `users:manage` (UserPolicy::assignRoles) and a fresh
 *    re-authentication;
 *  - an actor can only grant or remove roles whose permissions they hold
 *    themselves (an admin cannot grant `owner`, which includes
 *    `gateway:manage`: "admin: everything except gateway:manage and
 *    transferring ownership");
 *  - the last active owner cannot lose the owner role;
 *  - every assignment and revocation is audited; sensitive assignments notify
 *    the owners and the affected user.
 */
final readonly class ChangeUserRoles
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private OwnerGuard $owners,
        private AuditLogger $audit,
        private TenantOwners $tenantOwners,
        private RoleGrantGuard $grants,
        private TenantLock $tenantLock,
    ) {}

    /**
     * @param  list<SystemRole>  $roles
     */
    public function handle(User $actor, User $target, array $roles): User
    {
        Gate::forUser($actor)->authorize('assignRoles', $target);
        $this->reauthentication->ensureConfirmed();

        if ($roles === []) {
            throw RoleChangeNotAllowedException::noRoles();
        }

        $new = array_values(array_unique(array_map(static fn (SystemRole $role): string => $role->value, $roles)));

        [$user, $added] = DB::transaction(function () use ($actor, $target, $new): array {
            // Lock order: tenant row, then user row. Serializes every owner
            // change of the tenant, so two concurrent demotions cannot both
            // pass the last-owner check.
            $this->tenantLock->lock($target->tenant_id);
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $current = $locked->roles->pluck('name')->filter(static fn (mixed $name): bool => is_string($name))->values()->all();

            $added = array_values(array_diff($new, $current));
            $removed = array_values(array_diff($current, $new));

            foreach ([...$added, ...$removed] as $roleName) {
                $role = SystemRole::tryFrom($roleName);

                if ($role !== null && ! $this->grants->canGrant($actor, $role)) {
                    throw RoleChangeNotAllowedException::exceedsOwnPermissions();
                }
            }

            if (in_array(SystemRole::Owner->value, $removed, true) && $this->owners->isLastActiveOwner($locked)) {
                throw RoleChangeNotAllowedException::lastOwner();
            }

            $locked->syncRoles($new);

            foreach ($added as $roleName) {
                $this->audit->record(AuditAction::RoleAssigned, $locked, ['role' => $roleName, 'before' => $current, 'after' => $new], actor: Actor::user($actor->id));
            }

            foreach ($removed as $roleName) {
                $this->audit->record(AuditAction::RoleRevoked, $locked, ['role' => $roleName, 'before' => $current, 'after' => $new], actor: Actor::user($actor->id));
            }

            return [$locked, $added];
        });

        $this->notifySensitiveAssignments($user, $added);

        return $user;
    }

    /**
     * @param  list<string>  $added
     */
    private function notifySensitiveAssignments(User $user, array $added): void
    {
        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        foreach ($added as $roleName) {
            $role = SystemRole::tryFrom($roleName);

            if ($role === null || ! $role->isSensitive()) {
                continue;
            }

            $recipients = $this->tenantOwners->of($tenant)->push($user)->unique('id');
            Notification::send($recipients, new SensitiveRoleAssignedNotification($role));
        }
    }
}
