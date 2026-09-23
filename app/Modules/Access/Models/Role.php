<?php

declare(strict_types=1);

namespace App\Modules\Access\Models;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
