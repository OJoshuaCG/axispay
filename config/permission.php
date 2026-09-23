<?php

declare(strict_types=1);

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Access\TenantTeamResolver;

/*
|--------------------------------------------------------------------------
| spatie/laravel-permission (ADR-014, plan 17)
|--------------------------------------------------------------------------
|
| Teams are enabled and the team is the tenant: `team_id` holds the tenant
| ULID and follows the TenantContext (TenantTeamResolver). System roles are
| global (`roles.team_id` NULL) and assigned per tenant. Roles, permissions
| and pivots use ULID/char(26) ascii_bin keys (migration
| 2026_09_23_000400_create_permission_tables.php), not the package stub.
|
*/

return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        'team' => null,
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    // Authorization goes through Policies that call hasPermissionTo()
    // (ADR-014). The package's Gate::before hook is off so the only global
    // Gate hook is the read-only impersonation guard (plan 17.4).
    'register_permission_check_method' => false,

    'register_octane_reset_listener' => false,

    'events_enabled' => false,

    'teams' => true,

    'team_resolver' => TenantTeamResolver::class,

    'use_passport_client_credentials' => false,

    // Never leak permission or role names in exception messages.
    'display_permission_in_exception' => false,

    'display_role_in_exception' => false,

    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
