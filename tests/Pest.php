<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\PermissionCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Every test boots the Laravel application (config is needed even by money
| and ID tests). Feature tests hit the real MariaDB configured in phpunit.xml.
|
| Phase 1 feature areas run inside a transaction (RefreshDatabase) with the
| permission catalog seeded. Tests that need DDL (probe tables) live in
| Feature/Database and Feature/Schema and manage their own tables, because
| DDL commits implicitly in MariaDB.
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        seed(PermissionCatalogSeeder::class);
    })
    ->in('Feature/Tenancy', 'Feature/Identity', 'Feature/Access', 'Feature/Audit', 'Feature/PlatformAdmin', 'Feature/Panels', 'Feature/Isolation', 'Feature/Console');

/**
 * Absolute URL on the public API host (ADR-027).
 */
function apiUrl(string $path): string
{
    $host = config('axispay.surfaces.api');

    return 'http://'.(is_string($host) ? $host : 'api.localhost').'/'.ltrim($path, '/');
}

/**
 * Absolute URL on the tenant panel host.
 */
function appUrl(string $path = '/'): string
{
    return 'http://'.config()->string('axispay.surfaces.app').'/'.ltrim($path, '/');
}

/**
 * Absolute URL on the platform panel host.
 */
function adminUrl(string $path = '/'): string
{
    return 'http://'.config()->string('axispay.surfaces.admin').'/'.ltrim($path, '/');
}

/**
 * A tenant user with the given system roles, created in the tenant's context.
 *
 * @param  list<SystemRole>  $roles
 */
function tenantUser(?Tenant $tenant = null, array $roles = [SystemRole::Owner], bool $twoFactor = true): User
{
    $tenant ??= Tenant::factory()->create();
    $factory = User::factory()->forTenant($tenant);
    $factory = $twoFactor ? $factory->withTwoFactor() : $factory;

    $user = app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($factory, $roles): User {
        $user = $factory->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        return $user;
    });

    return $user->refresh();
}

/**
 * Signs a tenant user in for panel/Livewire tests: web guard, tenant context
 * (normally set by ResolveTenantContext) and the current Filament panel.
 */
function actingAsTenantUser(User $user, bool $livemode = false): User
{
    actingAs($user, 'web');
    app(TenantContext::class)->set($user->tenant_id, $livemode);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return $user;
}

function platformAdmin(bool $superadmin = true, bool $twoFactor = true): PlatformAdmin
{
    $factory = PlatformAdmin::factory();
    $factory = $superadmin ? $factory : $factory->supportReadonly();

    return ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
}

function actingAsPlatformAdmin(PlatformAdmin $admin): PlatformAdmin
{
    actingAs($admin, 'platform');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

function activeTenant(TenantStatus $status = TenantStatus::Active): Tenant
{
    return Tenant::factory()->status($status)->create();
}
