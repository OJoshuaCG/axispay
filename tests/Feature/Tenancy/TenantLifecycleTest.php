<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\Tenancy\Actions\ChangeTenantStatus;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Data\CreateTenantData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantStatusTransitionException;
use App\Modules\Tenancy\Exceptions\TenantCloseNotConfirmedException;
use App\Modules\Tenancy\Notifications\TenantStatusChangedNotification;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('creates a tenant in pending_onboarding, audited, and invites its owner', function (): void {
    Notification::fake();
    $admin = platformAdmin();

    $tenant = app(CreateTenant::class)->handle($admin, new CreateTenantData(
        legalName: 'Acme S.A. de C.V.',
        displayName: 'Acme',
        ownerEmail: 'Owner@Acme.test',
    ));

    expect($tenant->status)->toBe(TenantStatus::PendingOnboarding);

    $audit = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::TenantCreated->value)->sole();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->actor_id)->toBe($admin->id);

    $invitation = app(TenantContext::class)->runAsTenant($tenant->id, false, fn () => UserInvitation::query()->sole());
    expect($invitation->email)->toBe('owner@acme.test')
        ->and($invitation->role_name)->toBe(SystemRole::Owner->value);

    Notification::assertSentOnDemand(UserInvitationNotification::class);
});

it('lets only superadmins create tenants', function (): void {
    app(CreateTenant::class)->handle(platformAdmin(superadmin: false), new CreateTenantData('X', 'X'));
})->throws(AuthorizationException::class);

it('changes status through allowed transitions only, with a reason, audited and notifying owners', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant();
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    tenantUser($tenant, [SystemRole::Viewer]);

    $updated = app(ChangeTenantStatus::class)->handle($admin, $tenant, new ChangeTenantStatusData(TenantStatus::Suspended, 'Unpaid invoices'));

    expect($updated->status)->toBe(TenantStatus::Suspended)
        ->and($updated->status_reason)->toBe('Unpaid invoices');

    $audit = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::TenantStatusChanged->value)->sole();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->changes)->toMatchArray(['before' => ['status' => 'active'], 'after' => ['status' => 'suspended'], 'reason' => 'Unpaid invoices']);

    Notification::assertSentTo($owner, TenantStatusChangedNotification::class);
    Notification::assertCount(1);
});

it('rejects forbidden transitions and blank reasons', function (): void {
    $admin = platformAdmin();
    $tenant = activeTenant(TenantStatus::PendingOnboarding);

    expect(fn () => app(ChangeTenantStatus::class)->handle($admin, $tenant, new ChangeTenantStatusData(TenantStatus::Suspended, 'x')))
        ->toThrow(InvalidTenantStatusTransitionException::class)
        ->and(fn () => app(ChangeTenantStatus::class)->handle($admin, $tenant, new ChangeTenantStatusData(TenantStatus::Active, '   ')))
        ->toThrow(InvalidArgumentException::class);
});

it('requires the display name to be retyped to close a tenant', function (): void {
    Notification::fake();
    $admin = platformAdmin();
    $tenant = activeTenant();

    expect(fn () => app(ChangeTenantStatus::class)->handle($admin, $tenant, new ChangeTenantStatusData(TenantStatus::Closed, 'Contract ended', 'wrong')))
        ->toThrow(TenantCloseNotConfirmedException::class);

    $closed = app(ChangeTenantStatus::class)->handle($admin, $tenant, new ChangeTenantStatusData(TenantStatus::Closed, 'Contract ended', $tenant->display_name));

    expect($closed->status)->toBe(TenantStatus::Closed)
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->status->allowedTransitions())->toBe([]);
});

it('lets only superadmins change the status', function (): void {
    app(ChangeTenantStatus::class)->handle(platformAdmin(superadmin: false), activeTenant(), new ChangeTenantStatusData(TenantStatus::Grace, 'x'));
})->throws(AuthorizationException::class);
