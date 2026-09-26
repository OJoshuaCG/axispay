<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Services;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Enums\TenantOwnershipState;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Has an active owner" for the platform panel (plan 17.2, ADR-0045): at
 * least one user of the tenant that is not deactivated holds the global
 * `owner` role in the tenant's team (team = tenant).
 *
 * Built as correlated sub-queries on `tenants`, so the tenant list gets the
 * state of every row in the same query (no N+1):
 *
 *   EXISTS (SELECT 1 FROM roles
 *            WHERE roles.team_id IS NULL AND roles.name = 'owner' AND roles.guard_name = 'web'
 *              AND EXISTS (SELECT * FROM users JOIN model_has_roles ON users.id = model_has_roles.model_id
 *                           WHERE roles.id = model_has_roles.role_id AND model_has_roles.model_type = <User>
 *                             AND users.tenant_id = tenants.id AND model_has_roles.team_id = tenants.id
 *                             AND users.disabled_at IS NULL))
 *
 * Only Eloquent models and relations are used (Role::tenantUsers(), the tenant
 * scope dropped on User and UserInvitation), never raw SQL or a query builder
 * on a tenant table. Dropping the scope is allowed here because the
 * PlatformAdmin module is on the scope-bypass whitelist (ADR-0031); every
 * sub-query is pinned to `tenants.id`, so it never mixes tenants. spatie's
 * own `roles()` relation cannot be used: it filters by the team of the
 * current TenantContext, and the platform panel has none.
 *
 * Selecting owners by role is a domain invariant (the owner minimum), not an
 * authorization check.
 */
final readonly class TenantOwnership
{
    /** Selected by withOwnershipColumns(): 1 when an active owner exists, NULL otherwise. */
    public const string ACTIVE_OWNER = 'ownership_active_owner';

    /** Selected by withOwnershipColumns(): expiry of the newest valid owner invitation, or NULL. */
    public const string PENDING_INVITATION_EXPIRES_AT = 'ownership_invitation_expires_at';

    public function __construct(private TenantContext $context) {}

    /**
     * Adds the two ownership columns to a tenant query.
     *
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function withOwnershipColumns(Builder $query): Builder
    {
        return $query->addSelect([
            self::ACTIVE_OWNER => $this->activeOwnerRole()->selectRaw('1')->limit(1),
            self::PENDING_INVITATION_EXPIRES_AT => $this->pendingOwnerInvitations()
                ->select('user_invitations.expires_at')
                ->orderByDesc('user_invitations.expires_at')
                ->limit(1),
        ]);
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Builder<Tenant>
     */
    public function whereHasActiveOwner(Builder $query, bool $has = true): Builder
    {
        return $has
            ? $query->whereExists($this->activeOwnerRole())
            : $query->whereNotExists($this->activeOwnerRole());
    }

    /**
     * The state of a tenant. Uses the columns of withOwnershipColumns() when
     * the model was loaded with them (tenant list), or queries them.
     */
    public function stateOf(Tenant $tenant): TenantOwnershipState
    {
        $tenant = $this->withColumns($tenant);

        return match (true) {
            $tenant->getAttribute(self::ACTIVE_OWNER) !== null => TenantOwnershipState::Active,
            $tenant->getAttribute(self::PENDING_INVITATION_EXPIRES_AT) !== null => TenantOwnershipState::PendingInvitation,
            default => TenantOwnershipState::None,
        };
    }

    public function hasActiveOwner(Tenant $tenant): bool
    {
        return $this->stateOf($tenant) === TenantOwnershipState::Active;
    }

    /**
     * The newest owner invitation that was neither accepted nor revoked
     * (pending or expired), for the view page's warning and its quick
     * "Resend" action. Null when there is none.
     */
    public function latestOpenOwnerInvitation(Tenant $tenant): ?UserInvitation
    {
        return UserInvitation::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('role_name', SystemRole::Owner->value)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    /**
     * IDs of the tenant's users holding the owner role (active or not), read
     * in the tenant's own context so spatie resolves the right team. One
     * query per list render.
     *
     * @return list<string>
     */
    public function ownerIds(Tenant $tenant): array
    {
        return $this->context->runAsTenant($tenant->id, false, static fn (): array => array_values(User::role(SystemRole::Owner->value)
            ->pluck('users.id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->all()));
    }

    /**
     * The global owner role, when some active user of the outer `tenants`
     * row holds it in that tenant's team.
     *
     * @return Builder<Role>
     */
    private function activeOwnerRole(): Builder
    {
        return Role::query()
            ->whereNull('roles.team_id')
            ->where('roles.name', SystemRole::Owner->value)
            ->where('roles.guard_name', 'web')
            ->whereHas('tenantUsers', static function (Builder $users): void {
                $users->withoutGlobalScope(TenantScope::class)
                    ->whereColumn('users.tenant_id', 'tenants.id')
                    ->whereColumn('model_has_roles.team_id', 'tenants.id')
                    ->whereNull('users.disabled_at');
            });
    }

    /**
     * Owner invitations of the outer `tenants` row that can still be accepted.
     *
     * @return Builder<UserInvitation>
     */
    private function pendingOwnerInvitations(): Builder
    {
        return UserInvitation::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereColumn('user_invitations.tenant_id', 'tenants.id')
            ->where('user_invitations.role_name', SystemRole::Owner->value)
            ->whereNull('user_invitations.accepted_at')
            ->whereNull('user_invitations.revoked_at')
            ->where('user_invitations.expires_at', '>', now());
    }

    private function withColumns(Tenant $tenant): Tenant
    {
        if (array_key_exists(self::ACTIVE_OWNER, $tenant->getAttributes())) {
            return $tenant;
        }

        return $this->withOwnershipColumns(Tenant::query()->whereKey($tenant->getKey()))->firstOrFail();
    }
}
