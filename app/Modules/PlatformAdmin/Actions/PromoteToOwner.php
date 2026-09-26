<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Notifications\OwnerGrantedByPlatformNotification;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Exceptions\OwnerPromotionNotAllowedException;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantLock;
use App\Modules\Tenancy\Services\TenantOwners;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Grants the owner role to an active user of a tenant, from the platform
 * panel (plan 17.2 "minimum one owner per tenant", ADR-0045). The recovery
 * path when a tenant has no usable owner and the intended person already has
 * an account (e-mails are unique across the platform, so they cannot be
 * invited again).
 *
 *  - superadmin only (TenantPolicy::promoteOwner), never into a closed
 *    tenant; a reason of at least 10 characters; a fresh re-authentication
 *    of the platform admin (plan 17.3);
 *  - the user's other roles are kept (the owner role is added, a union):
 *    owner already holds every permission, so removing the rest would only
 *    lose information about the user's previous job;
 *  - a platform-only path: it never goes through the tenant-side
 *    RoleGrantGuard, which keeps refusing tenant admins who try to grant
 *    owner;
 *  - lock order tenant row, then user row, like ChangeUserRoles and
 *    DeactivateUser, so it serializes with every other owner change;
 *  - audited as `owner.promoted` in the platform log and the tenant's log
 *    (like impersonation); the owners and the promoted user are e-mailed.
 *
 * There is no platform "remove owner": the last-owner protection stays with
 * the tenant's own role management.
 */
final readonly class PromoteToOwner
{
    public const int MIN_REASON_LENGTH = 10;

    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private TenantContext $context,
        private TenantLock $tenantLock,
        private AuditLogger $audit,
        private TenantOwners $owners,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     * @throws OwnerPromotionNotAllowedException
     * @throws ModelNotFoundException<User> when the user does not belong to the tenant
     */
    public function handle(PlatformAdmin $actor, Tenant $tenant, User $target, string $reason): User
    {
        Gate::forUser($actor)->authorize('promoteOwner', $tenant);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw OwnerPromotionNotAllowedException::reasonRequired();
        }

        $this->reauthentication->ensureConfirmed();

        if ($target->tenant_id !== $tenant->id) {
            throw (new ModelNotFoundException)->setModel(User::class, [$target->id]);
        }

        $promoted = $this->context->runAsTenant($tenant->id, false, fn (): User => DB::transaction(function () use ($actor, $tenant, $target, $reason): User {
            $lockedTenant = $this->tenantLock->lock($tenant->id);

            if ($lockedTenant->status === TenantStatus::Closed) {
                throw OwnerPromotionNotAllowedException::tenantClosed();
            }

            // Tenant-scoped lookup: another tenant's user is simply not found.
            $user = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($user->isDisabled()) {
                throw OwnerPromotionNotAllowedException::inactiveUser();
            }

            $before = array_values($user->roles()->pluck('name')->filter(static fn (mixed $name): bool => is_string($name))->all());

            if (in_array(SystemRole::Owner->value, $before, true)) {
                throw OwnerPromotionNotAllowedException::alreadyOwner();
            }

            $user->assignRole(SystemRole::Owner->value);

            $changes = [
                'user_id' => $user->id,
                'role' => SystemRole::Owner->value,
                'before' => $before,
                'after' => [...$before, SystemRole::Owner->value],
                'reason' => $reason,
            ];
            $auditActor = Actor::platformAdmin($actor->id);
            $this->audit->record(AuditAction::OwnerPromoted, $user, [...$changes, 'tenant_id' => $tenant->id], platform: true, actor: $auditActor);
            $this->audit->record(AuditAction::OwnerPromoted, $user, $changes, tenantId: $tenant->id, actor: $auditActor);

            return $user;
        }));

        $recipients = $this->owners->of($tenant)->push($promoted)->unique('id');
        Notification::send($recipients, (new OwnerGrantedByPlatformNotification($tenant->display_name, $promoted->id))->locale($tenant->default_locale));

        return $promoted;
    }
}
