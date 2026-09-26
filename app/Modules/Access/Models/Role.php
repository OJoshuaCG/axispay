<?php

declare(strict_types=1);

namespace App\Modules\Access\Models;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role with a ULID key (ADR-020). `team_id` NULL marks a global system role
 * (plan 17.2); a non-null `team_id` is a tenant-specific role (custom roles
 * are outside the MVP, but the model supports them).
 *
 * No global scope here on purpose: spatie caches the permission/role map for
 * all tenants, so a tenant filter on this model would poison that cache.
 * Tenant-facing queries use visibleToCurrentTenant().
 *
 * @property string $id
 * @property string|null $team_id
 */
final class Role extends SpatieRole
{
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    public function isSystem(): bool
    {
        return $this->team_id === null;
    }

    public function systemRole(): ?SystemRole
    {
        return $this->isSystem() ? SystemRole::tryFrom($this->name) : null;
    }

    /**
     * Tenant users holding this role, in ANY team. Unlike spatie's users(),
     * the related model does not depend on the default auth guard (which is
     * `platform` in the admin panel). No team filter: callers must constrain
     * `model_has_roles.team_id` themselves (TenantOwnership pins it to the
     * user's tenant). User keeps its fail-closed tenant scope.
     *
     * @return MorphToMany<User, $this>
     */
    public function tenantUsers(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'model', 'model_has_roles', 'role_id', 'model_id');
    }

    public function displayName(): string
    {
        return $this->systemRole()?->label() ?? $this->name;
    }

    /**
     * Roles a tenant can see: the global system roles plus its own roles.
     * Fail-closed: throws without a tenant context.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleToCurrentTenant(Builder $query): void
    {
        $tenantId = app(TenantContext::class)->idOrFail(self::class);

        $query->where(static function (Builder $query) use ($tenantId): void {
            $query->whereNull('team_id')->orWhere('team_id', $tenantId);
        });
    }
}
