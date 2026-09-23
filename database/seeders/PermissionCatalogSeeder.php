<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission catalog (plan 17.1) and global system roles (plan 17.2).
 * Idempotent: safe to run on every deploy. System roles have team_id NULL and
 * are assigned per tenant. Existing role permissions are synced to the
 * catalog, so the seeder is also how a catalog change reaches production.
 */
final class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (TenantPermission::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => TenantPermission::GUARD]);
        }

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->firstOrCreate([
                'name' => $systemRole->value,
                'guard_name' => TenantPermission::GUARD,
                'team_id' => null,
            ]);

            $role->syncPermissions(array_map(static fn (TenantPermission $permission): string => $permission->value, $systemRole->permissions()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
