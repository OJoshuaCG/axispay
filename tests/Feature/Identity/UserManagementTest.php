<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\DeactivateUser;
use App\Modules\Identity\Actions\ReactivateUser;
use App\Modules\Identity\Exceptions\CannotDeactivateUserException;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

beforeEach(function (): void {
    startSession();
    app(ReauthenticationWindow::class)->confirm();
});

it('deactivates and reactivates users, audited', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    app(DeactivateUser::class)->handle($owner, $target);
    expect($target->refresh()->isDisabled())->toBeTrue();

    app(ReactivateUser::class)->handle($owner, $target->refresh());
    expect($target->refresh()->isDisabled())->toBeFalse();

    expect(AuditLog::query()->whereIn('action', [AuditAction::UserDeactivated->value, AuditAction::UserReactivated->value])->count())->toBe(2);
});

it('does not let a user deactivate themselves', function (): void {
    $owner = actingAsTenantUser(tenantUser());

    app(DeactivateUser::class)->handle($owner, $owner);
})->throws(AuthorizationException::class);

it('keeps at least one active owner', function (): void {
    // Admin + integration manager together hold every permission, so the
    // hierarchy rule allows acting on the owner; the last-owner rule must not.
    $actor = actingAsTenantUser(tenantUser(roles: [SystemRole::Admin, SystemRole::IntegrationManager]));
    $owner = tenantUser($actor->tenant, [SystemRole::Owner]);

    app(DeactivateUser::class)->handle($actor, $owner);
})->throws(CannotDeactivateUserException::class);

it('does not let an admin deactivate an owner (hierarchy, M1)', function (): void {
    $admin = actingAsTenantUser(tenantUser(roles: [SystemRole::Admin]));
    tenantUser($admin->tenant, [SystemRole::Owner]);
    $otherOwner = tenantUser($admin->tenant, [SystemRole::Owner]);

    expect(fn () => app(DeactivateUser::class)->handle($admin, $otherOwner))->toThrow(AuthorizationException::class);

    // Even when the policy is bypassed, the action refuses.
    Gate::before(static fn (): bool => true);
    expect(fn () => app(DeactivateUser::class)->handle($admin, $otherOwner))->toThrow(CannotDeactivateUserException::class);
});

it('requires a fresh re-authentication to deactivate', function (): void {
    session()->forget(ReauthenticationWindow::SESSION_KEY);
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    app(DeactivateUser::class)->handle($owner, $target);
})->throws(ReauthenticationRequiredException::class);

it('requires users:manage to deactivate', function (): void {
    $finance = actingAsTenantUser(tenantUser(roles: [SystemRole::Finance]));
    $target = tenantUser($finance->tenant, [SystemRole::Viewer]);

    app(DeactivateUser::class)->handle($finance, $target);
})->throws(AuthorizationException::class);

it('locks deactivated users and closed tenants out of the panel', function (): void {
    $viewer = tenantUser(roles: [SystemRole::Viewer], twoFactor: false);
    actingAs($viewer, 'web');
    get(appUrl('/'))->assertOk();

    $viewer->forceFill(['disabled_at' => now()])->saveQuietly();
    get(appUrl('/'))->assertForbidden();

    $other = tenantUser(activeTenant(TenantStatus::Closed), [SystemRole::Viewer], twoFactor: false);
    actingAs($other, 'web');
    get(appUrl('/'))->assertForbidden();
});
