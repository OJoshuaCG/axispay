<?php

declare(strict_types=1);

use App\Modules\Access\Actions\ChangeUserRoles;
use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Exceptions\RoleChangeNotAllowedException;
use App\Modules\Access\Notifications\SensitiveRoleAssignedNotification;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\DeactivateUser;
use App\Modules\Identity\Services\ReauthenticationWindow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\startSession;

beforeEach(function (): void {
    startSession();
    app(ReauthenticationWindow::class)->confirm();
});

it('replaces roles, audits each change and notifies sensitive assignments', function (): void {
    Notification::fake();
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    app(ChangeUserRoles::class)->handle($owner, $target, [SystemRole::Finance]);

    expect($target->refresh()->getRoleNames()->all())->toBe(['finance'])
        ->and(AuditLog::query()->where('action', AuditAction::RoleAssigned->value)->sole()->changes)->toMatchArray(['role' => 'finance'])
        ->and(AuditLog::query()->where('action', AuditAction::RoleRevoked->value)->sole()->changes)->toMatchArray(['role' => 'viewer']);

    Notification::assertSentTo([$owner, $target], SensitiveRoleAssignedNotification::class);
});

it('does not let an admin grant roles with permissions it lacks (no ownership transfer)', function (): void {
    $admin = actingAsTenantUser(tenantUser(roles: [SystemRole::Admin]));
    tenantUser($admin->tenant, [SystemRole::Owner]);
    $target = tenantUser($admin->tenant, [SystemRole::Viewer]);

    expect(fn () => app(ChangeUserRoles::class)->handle($admin, $target, [SystemRole::Owner]))
        ->toThrow(RoleChangeNotAllowedException::class)
        ->and(fn () => app(ChangeUserRoles::class)->handle($admin, $target, [SystemRole::IntegrationManager]))
        ->toThrow(RoleChangeNotAllowedException::class);

    app(ChangeUserRoles::class)->handle($admin, $target, [SystemRole::Finance]);
    expect($target->refresh()->hasRole('finance'))->toBeTrue();
});

it('never removes the owner role from the last active owner', function (): void {
    $owner = tenantUser();
    $secondOwner = actingAsTenantUser(tenantUser($owner->tenant, [SystemRole::Owner]));

    // Two owners: demoting one is fine.
    app(ChangeUserRoles::class)->handle($secondOwner, $owner, [SystemRole::Admin]);

    // Now $secondOwner is the last owner; nobody can demote them.
    $otherOwnerCandidate = tenantUser($owner->tenant, [SystemRole::Admin]);
    actingAsTenantUser($otherOwnerCandidate);

    expect(fn () => app(ChangeUserRoles::class)->handle($otherOwnerCandidate, $secondOwner, [SystemRole::Viewer]))
        ->toThrow(RoleChangeNotAllowedException::class);
});

it('does not let users change their own roles', function (): void {
    $owner = actingAsTenantUser(tenantUser());

    app(ChangeUserRoles::class)->handle($owner, $owner, [SystemRole::Viewer]);
})->throws(AuthorizationException::class);

/**
 * M2: owner changes serialize on the tenant row. The first row lock taken
 * inside the transaction must be on `tenants`, before the user row and before
 * the last-owner check reads the other owners.
 *
 * @return list<string>
 */
function lockingQueriesDuring(Closure $callback): array
{
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

it('locks the tenant row before checking the last owner (M2)', function (): void {
    $owner = tenantUser();
    $secondOwner = actingAsTenantUser(tenantUser($owner->tenant, [SystemRole::Owner]));

    $queries = lockingQueriesDuring(fn () => app(ChangeUserRoles::class)->handle($secondOwner, $owner, [SystemRole::Admin]));

    $locks = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update')));
    $tenantLock = array_search($locks[0] ?? '', $queries, true);
    $ownerCheck = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'model_has_roles') && str_contains($sql, 'exists')));

    expect($locks[0] ?? '')->toContain('`tenants`')
        ->and($locks[1] ?? '')->toContain('`users`')
        ->and($ownerCheck)->toBeInt()
        ->and($tenantLock)->toBeLessThan((int) $ownerCheck);
});

it('locks the tenant row first when deactivating (M2)', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    $queries = lockingQueriesDuring(fn () => app(DeactivateUser::class)->handle($owner, $target));
    $locks = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update')));

    expect($locks[0] ?? '')->toContain('`tenants`')
        ->and($locks[1] ?? '')->toContain('`users`');
});
