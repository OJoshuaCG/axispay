<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\Access\Models\Role;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use function Pest\Laravel\get;

/**
 * Tenant isolation (plan 6.6). For every tenant-panel resource, a user of
 * tenant A never sees tenant B's rows in lists and gets a 404 (never 403) on
 * B's records. The route coverage test below fails when a new tenant route is
 * registered without being added to this dataset (or to the reviewed list of
 * non-resource routes).
 */

/**
 * Resource slug => [list page, record factory for a given tenant].
 *
 * @return array<string, array{class-string, Closure(Tenant): Model}>
 */
function isolatedAppResources(): array
{
    return [
        'users' => [ListUsers::class, static fn (Tenant $tenant): Model => tenantUser($tenant, [SystemRole::Viewer])],
        'roles' => [ListRoles::class, static fn (Tenant $tenant): Model => app(TenantContext::class)->runAsTenant(
            $tenant->id,
            false,
            static fn (): Model => Role::query()->create(['name' => 'custom-'.$tenant->id, 'guard_name' => TenantPermission::GUARD, 'team_id' => $tenant->id]),
        )],
        'audit-logs' => [ListAuditLogs::class, static fn (Tenant $tenant): Model => app(AuditLogger::class)->record(AuditAction::LivemodeSwitched, tenantId: $tenant->id)],
    ];
}

/**
 * App-host routes that are not Filament resources, each reviewed for
 * isolation: none takes a tenant record from the URL.
 *
 * @var array<string, string>
 */
const REVIEWED_APP_ROUTES = [
    'invitations.show' => 'Token lookup only; the tenant comes from the invitation.',
    'invitations.accept' => 'Token lookup only; the tenant comes from the invitation.',
    'impersonation.consume' => 'Token lookup only; covered by ImpersonationTest.',
    'impersonation.stop' => 'Acts on the session only.',
    'app.livemode.update' => 'Acts on the session only; tenant from the user.',
];

/**
 * Asserts that $viewer (tenant A) cannot list or open $foreign (tenant B),
 * while they can list and open $own.
 *
 * @param  class-string  $listPage
 */
function assertTenantIsolation(User $viewer, string $slug, string $listPage, Model $own, Model $foreign): void
{
    actingAsTenantUser($viewer);

    Livewire::test($listPage)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    get(appUrl("/{$slug}/".routeKeyOf($own)))->assertOk();
    get(appUrl("/{$slug}/".routeKeyOf($foreign)))->assertNotFound();
}

function routeKeyOf(Model $model): string
{
    $key = $model->getRouteKey();

    return is_string($key) ? $key : throw new LogicException('Expected a string route key.');
}

dataset('isolated_app_resources', fn (): array => array_map(
    static fn (array $resource, string $slug): array => [$slug, ...$resource],
    isolatedAppResources(),
    array_keys(isolatedAppResources()),
));

it('isolates tenant panel resources between tenants', function (string $slug, string $listPage, Closure $factory): void {
    /** @var class-string $listPage */
    [$a, $b] = [activeTenant(), activeTenant()];
    $viewer = tenantUser($a, [SystemRole::Owner]);
    $own = $factory($a);
    $foreign = $factory($b);
    assert($own instanceof Model && $foreign instanceof Model);

    assertTenantIsolation($viewer, $slug, $listPage, $own, $foreign);
})->with('isolated_app_resources');

it('covers every tenant-panel route with an isolation test', function (): void {
    $appHost = config()->string('paylink.surfaces.app');
    $covered = array_keys(isolatedAppResources());
    $uncovered = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if ($route->getDomain() !== $appHost || str_starts_with($name, 'filament.app.auth.') || $name === 'filament.app.pages.dashboard') {
            continue;
        }

        if (preg_match('/^filament\.app\.resources\.([^.]+)\./', $name, $match) === 1) {
            if (! in_array($match[1], $covered, true)) {
                $uncovered[] = $name;
            }

            continue;
        }

        if (! array_key_exists($name, REVIEWED_APP_ROUTES) && ! str_starts_with($name, 'filament.')) {
            $uncovered[] = $name !== '' ? $name : $route->uri();
        }
    }

    expect($uncovered)->toBe([], 'Add an isolation test (dataset above) for: '.implode(', ', $uncovered));
});

it('has no API routes without an isolation dataset yet (Phase 3 adds them)', function (): void {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (RouteDefinition $route): bool => $route->getDomain() === config()->string('paylink.surfaces.api'))
        ->map(static fn (RouteDefinition $route): string => (string) $route->getName())
        ->values()
        ->all();

    expect($apiRoutes)->toBe([]);
});
