<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * spatie/laravel-permission team resolver: team = tenant (ADR-014).
 *
 * The team always follows the scoped TenantContext, so permission checks use
 * the current tenant's role assignments and nothing leaks between requests or
 * jobs (the PermissionRegistrar holding this resolver is a singleton, so a
 * stored team ID would survive into the next job of a queue worker). Setting a
 * team explicitly is therefore refused: use TenantContext::runAsTenant().
 */
final class TenantTeamResolver implements PermissionsTeamResolver
{
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id !== null) {
            throw new LogicException('The permission team follows the TenantContext; use TenantContext::runAsTenant() instead.');
        }
    }

    public function getPermissionsTeamId(): ?string
    {
        return app(TenantContext::class)->idOrNull();
    }
}
