<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\PermissionCatalogSeeder;

use function Pest\Laravel\seed;

it('seeds the permission catalog of plan 17.1', function (): void {
    expect(Permission::query()->pluck('name')->all())->toEqualCanonicalizing(TenantPermission::values());
});

it('seeds the system roles of plan 17.2 as global roles', function (SystemRole $role, array $expected): void {
    $model = Role::query()->where('name', $role->value)->sole();

    expect($model->team_id)->toBeNull()
        ->and($model->permissions->pluck('name')->all())->toEqualCanonicalizing($expected);
})->with([
    'owner' => [SystemRole::Owner, TenantPermission::values()],
    'admin' => [SystemRole::Admin, array_values(array_diff(TenantPermission::values(), ['gateway:manage']))],
    'integration_manager' => [SystemRole::IntegrationManager, ['api_keys:manage', 'webhooks:manage', 'gateway:manage', 'links:read', 'payments:read']],
    'finance' => [SystemRole::Finance, ['links:read', 'payments:read', 'payments:refund', 'metrics:read', 'reports:export']],
    'link_creator' => [SystemRole::LinkCreator, ['links:create', 'links:read', 'links:cancel']],
    'viewer' => [SystemRole::Viewer, ['links:read', 'payments:read', 'metrics:read']],
]);

it('is idempotent', function (): void {
    seed(PermissionCatalogSeeder::class);

    expect(Role::query()->count())->toBe(count(SystemRole::cases()))
        ->and(Permission::query()->count())->toBe(count(TenantPermission::cases()));
});

it('scopes role assignments to the tenant (team = tenant)', function (): void {
    $a = activeTenant();
    $user = tenantUser($a, [SystemRole::Owner]);

    app(TenantContext::class)->set($a->id, false);
    expect(User::query()->find($user->id)?->checkPermissionTo('users:manage'))->toBeTrue();

    // The same user seen from another tenant's context has no roles there.
    app(TenantContext::class)->runAsPlatform('test: cross-team check', function () use ($user): void {
        app(TenantContext::class)->runAsTenant(activeTenant()->id, false, function () use ($user): void {
            $fresh = User::query()->withoutGlobalScopes()->find($user->id);
            expect($fresh?->checkPermissionTo('users:manage'))->toBeFalse();
        });
    });
});

it('flags users with a sensitive permission as requiring 2FA', function (SystemRole $role, bool $required): void {
    $user = tenantUser(roles: [$role]);
    app(TenantContext::class)->set($user->tenant_id, false);

    expect($user->requiresTwoFactor())->toBe($required);
})->with([
    [SystemRole::Owner, true],
    [SystemRole::Admin, true],
    [SystemRole::IntegrationManager, true],
    [SystemRole::Finance, true],
    [SystemRole::LinkCreator, false],
    [SystemRole::Viewer, false],
]);
