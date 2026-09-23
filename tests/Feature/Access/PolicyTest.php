<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Models\Role;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Every permission-gated ability, for every system role (ADR-014: policies
 * check permissions, never role names).
 */
dataset('user_management_abilities', [
    'users.viewAny' => ['viewAny', User::class, 'users:manage'],
    'users.invite' => ['invite', User::class, 'users:manage'],
    'users.assignRoles' => ['assignRoles', 'target', 'users:manage'],
    'users.deactivate' => ['deactivate', 'target', 'users:manage'],
    'roles.viewAny' => ['viewAny', Role::class, 'users:manage'],
    'audit.viewAny' => ['viewAny', AuditLog::class, 'audit:read'],
]);

it('grants each ability exactly to the roles holding its permission', function (string $ability, string $subject, string $permission): void {
    foreach (SystemRole::cases() as $role) {
        $actor = tenantUser(roles: [$role]);
        app(TenantContext::class)->set($actor->tenant_id, false);
        $argument = $subject === 'target' ? tenantUser($actor->tenant, [SystemRole::Viewer]) : $subject;

        $holds = in_array($permission, array_map(static fn ($p): string => $p->value, $role->permissions()), true);

        expect(Gate::forUser($actor)->allows($ability, $argument))->toBe($holds, "{$role->value} / {$ability}");
    }
})->with('user_management_abilities');

it('never lets tenant users create, edit or delete roles', function (): void {
    $owner = tenantUser();
    app(TenantContext::class)->set($owner->tenant_id, false);
    $role = Role::query()->where('name', 'viewer')->sole();

    expect(Gate::forUser($owner)->allows('create', Role::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $role))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $role))->toBeFalse();
});

it('lets platform roles do what plan 17.4 allows', function (): void {
    $super = platformAdmin();
    $support = platformAdmin(superadmin: false);
    $tenant = activeTenant();
    $user = tenantUser($tenant);

    expect(Gate::forUser($super)->allows('create', Tenant::class))->toBeTrue()
        ->and(Gate::forUser($support)->allows('create', Tenant::class))->toBeFalse()
        ->and(Gate::forUser($support)->allows('viewAny', Tenant::class))->toBeTrue()
        ->and(Gate::forUser($super)->allows('changeStatus', $tenant))->toBeTrue()
        ->and(Gate::forUser($support)->allows('changeStatus', $tenant))->toBeFalse()
        ->and(Gate::forUser($super)->allows('impersonate', $user))->toBeTrue()
        ->and(Gate::forUser($support)->allows('impersonate', $user))->toBeFalse()
        ->and(Gate::forUser($super)->allows('update', Role::query()->firstOrFail()))->toBeTrue();
});
