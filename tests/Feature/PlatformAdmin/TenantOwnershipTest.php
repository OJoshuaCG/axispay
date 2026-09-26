<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Notifications\OwnerGrantedByPlatformNotification;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Actions\PromoteToOwner;
use App\Modules\PlatformAdmin\Enums\TenantOwnershipState;
use App\Modules\PlatformAdmin\Exceptions\OwnerPromotionNotAllowedException;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\CreateTenant as CreateTenantPage;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ListTenants;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Modules\PlatformAdmin\Services\TenantOwnership;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Actions\InviteTenantOwner;
use App\Modules\Tenancy\Data\CreateTenantData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * ADR-0045: every tenant is created with an owner invitation, the platform
 * panel shows whether a tenant has an active owner, and a superadmin can
 * grant the owner role to an active user of the tenant (PromoteToOwner).
 */

beforeEach(function (): void {
    startSession();
});

/**
 * @return Testable<UsersRelationManager>
 */
function ownershipUsersManager(Tenant $tenant): Testable
{
    return Livewire::test(UsersRelationManager::class, ['ownerRecord' => $tenant, 'pageClass' => ViewTenant::class]);
}

function ownershipInvite(Tenant $tenant, string $email): void
{
    app(InviteTenantOwner::class)->handle(platformAdmin(), $tenant, $email);
}

function hasOwnerRole(User $user): bool
{
    return app(TenantContext::class)->runAsTenant($user->tenant_id, false, static fn (): bool => $user->refresh()->hasRole(SystemRole::Owner->value));
}

/**
 * @return list<string>
 */
function roleNames(User $user): array
{
    return app(TenantContext::class)->runAsTenant($user->tenant_id, false, static fn (): array => array_values($user->refresh()->roles->pluck('name')
        ->filter(static fn (mixed $name): bool => is_string($name))
        ->sort()
        ->all()));
}

// --- Owner e-mail required -------------------------------------------------

it('requires a valid owner e-mail to create a tenant', function (string $email): void {
    app(CreateTenant::class)->handle(platformAdmin(), new CreateTenantData('Acme S.A.', 'Acme', $email));
})->with(['empty' => '', 'blank' => '   ', 'invalid' => 'not-an-email'])->throws(ValidationException::class);

it('refuses an owner e-mail that already belongs to a user, creating nothing', function (): void {
    Notification::fake();
    $existing = tenantUser(activeTenant(), [SystemRole::Viewer]);
    $before = Tenant::query()->count();

    expect(fn () => app(CreateTenant::class)->handle(platformAdmin(), new CreateTenantData('Acme S.A.', 'Acme', strtoupper($existing->email))))
        ->toThrow(ValidationException::class);

    expect(Tenant::query()->count())->toBe($before);
    Notification::assertNothingSent();
});

it('rejects an empty or taken owner e-mail on the create form', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $existing = tenantUser(activeTenant(), [SystemRole::Viewer]);
    $form = ['legal_name' => 'Form Co S.A.', 'display_name' => 'Form Co', 'timezone' => 'America/Mexico_City', 'default_locale' => 'es'];

    Livewire::test(CreateTenantPage::class)
        ->fillForm($form)
        ->call('create')
        ->assertHasFormErrors(['owner_email' => 'required']);

    Livewire::test(CreateTenantPage::class)
        ->fillForm([...$form, 'owner_email' => $existing->email])
        ->call('create')
        ->assertHasFormErrors(['owner_email']);

    expect(Tenant::query()->where('display_name', 'Form Co')->exists())->toBeFalse();
});

// --- Ownership state: list badge, filter, view warning ---------------------

it('computes the ownership state of each tenant: active, pending invitation or none', function (): void {
    Notification::fake();
    $active = activeTenant();
    tenantUser($active, [SystemRole::Owner]);
    $pending = activeTenant(TenantStatus::PendingOnboarding);
    ownershipInvite($pending, 'pending@owner.test');
    $none = activeTenant();
    tenantUser($none, [SystemRole::Admin]);
    $disabledOwner = activeTenant();
    tenantUser($disabledOwner, [SystemRole::Owner])->forceFill(['disabled_at' => now()])->saveQuietly();
    $expired = activeTenant();
    ownershipInvite($expired, 'expired@owner.test');
    app(TenantContext::class)->runAsTenant($expired->id, false, static fn (): int => UserInvitation::query()->update(['expires_at' => now()->subHour()]));

    $ownership = app(TenantOwnership::class);

    expect($ownership->stateOf($active))->toBe(TenantOwnershipState::Active)
        ->and($ownership->stateOf($pending))->toBe(TenantOwnershipState::PendingInvitation)
        ->and($ownership->stateOf($none))->toBe(TenantOwnershipState::None)
        ->and($ownership->stateOf($disabledOwner))->toBe(TenantOwnershipState::None)
        ->and($ownership->stateOf($expired))->toBe(TenantOwnershipState::None);

    $withoutOwner = $ownership->whereHasActiveOwner(Tenant::query(), false)->pluck('id')->all();
    expect($withoutOwner)->toContain($pending->id, $none->id, $disabledOwner->id, $expired->id);
    expect($withoutOwner)->not->toContain($active->id);
});

it('does not count an owner of another tenant', function (): void {
    $a = activeTenant();
    $b = activeTenant();
    tenantUser($b, [SystemRole::Owner]);
    tenantUser($a, [SystemRole::Viewer]);

    expect(app(TenantOwnership::class)->stateOf($a))->toBe(TenantOwnershipState::None)
        ->and(app(TenantOwnership::class)->stateOf($b))->toBe(TenantOwnershipState::Active);
});

it('computes the state under the admin panel guard too', function (): void {
    // The admin panel makes `platform` the default guard; the owner lookup
    // must still resolve tenant users (regression: spatie's Role::users()
    // follows the default guard).
    auth()->shouldUse('platform');
    $tenant = activeTenant();
    tenantUser($tenant, [SystemRole::Owner]);

    expect(app(TenantOwnership::class)->stateOf($tenant))->toBe(TenantOwnershipState::Active);
});

it('shows the owner badge on the tenant list and filters tenants without an active owner', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $active = activeTenant();
    tenantUser($active, [SystemRole::Owner]);
    $pending = activeTenant(TenantStatus::PendingOnboarding);
    ownershipInvite($pending, 'list@owner.test');
    $none = activeTenant();

    Livewire::test(ListTenants::class)
        ->assertTableColumnStateSet('ownership', TenantOwnershipState::Active->label(), $active)
        ->assertTableColumnStateSet('ownership', TenantOwnershipState::PendingInvitation->label(), $pending)
        ->assertTableColumnStateSet('ownership', TenantOwnershipState::None->label(), $none)
        ->filterTable('without_active_owner', true)
        ->assertCanSeeTableRecords([$pending, $none])
        ->assertCanNotSeeTableRecords([$active]);
});

it('warns on the tenant view while there is no active owner, with the pending invitation', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $pending = activeTenant(TenantStatus::PendingOnboarding);
    ownershipInvite($pending, 'callout@owner.test');
    $owned = activeTenant();
    tenantUser($owned, [SystemRole::Owner]);

    get(adminUrl('/tenants/'.$pending->id))
        ->assertOk()
        ->assertSee(__('platform.tenants.ownership.callout.heading'))
        ->assertSee('callout@owner.test');

    get(adminUrl('/tenants/'.$owned->id))
        ->assertOk()
        ->assertDontSee(__('platform.tenants.ownership.callout.heading'));
});

it('invites an owner from the warning on the tenant view', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction(TestAction::make('inviteOwner')->schemaComponent('ownership', 'infolist'), ['email' => 'quick@owner.test'])
        ->assertHasNoActionErrors();

    expect(app(TenantOwnership::class)->stateOf($tenant))->toBe(TenantOwnershipState::PendingInvitation);
});

// --- Promote to owner: the action -------------------------------------------

it('grants the owner role to an active user, keeping their roles, audited on both trails', function (): void {
    Notification::fake();
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();
    $tenant = activeTenant();
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $target = tenantUser($tenant, [SystemRole::Admin]);
    $viewer = tenantUser($tenant, [SystemRole::Viewer]);

    app(PromoteToOwner::class)->handle($admin, $tenant, $target, '  Owner left the company, ticket 4521  ');

    expect(roleNames($target))->toBe([SystemRole::Admin->value, SystemRole::Owner->value]);

    $entries = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::OwnerPromoted->value)->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('tenant_id')->all())->toEqualCanonicalizing([null, $tenant->id]);

    foreach ($entries as $entry) {
        expect($entry->actor_type)->toBe(ActorType::PlatformAdmin)
            ->and($entry->actor_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($target->id)
            ->and($entry->changes['reason'] ?? null)->toBe('Owner left the company, ticket 4521')
            ->and($entry->changes['role'] ?? null)->toBe(SystemRole::Owner->value);
    }

    Notification::assertSentTo([$owner, $target], OwnerGrantedByPlatformNotification::class);
    Notification::assertNotSentTo($viewer, OwnerGrantedByPlatformNotification::class);
    expect(new OwnerGrantedByPlatformNotification('Acme', $target->id))->toBeInstanceOf(ShouldQueue::class);
});

it('restores ownership of a tenant that has no owner at all', function (): void {
    Notification::fake();
    app(ReauthenticationWindow::class)->confirm();
    $tenant = activeTenant();
    $target = tenantUser($tenant, [SystemRole::Finance]);

    expect(app(TenantOwnership::class)->stateOf($tenant))->toBe(TenantOwnershipState::None);

    app(PromoteToOwner::class)->handle(platformAdmin(), $tenant, $target, 'No owner since onboarding');

    expect(app(TenantOwnership::class)->stateOf($tenant))->toBe(TenantOwnershipState::Active);
    Notification::assertSentTo($target, OwnerGrantedByPlatformNotification::class);
});

it('lets only superadmins grant the owner role', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $tenant = activeTenant();

    app(PromoteToOwner::class)->handle(platformAdmin(superadmin: false), $tenant, tenantUser($tenant, [SystemRole::Admin]), 'Support cannot do this');
})->throws(AuthorizationException::class);

it('requires a reason of at least 10 characters', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $tenant = activeTenant();

    app(PromoteToOwner::class)->handle(platformAdmin(), $tenant, tenantUser($tenant, [SystemRole::Admin]), '  short   ');
})->throws(OwnerPromotionNotAllowedException::class);

it('requires a fresh re-authentication of the platform admin', function (): void {
    session()->forget(ReauthenticationWindow::SESSION_KEY);
    $tenant = activeTenant();

    app(PromoteToOwner::class)->handle(platformAdmin(), $tenant, tenantUser($tenant, [SystemRole::Admin]), 'Owner left the company');
})->throws(ReauthenticationRequiredException::class);

it('refuses a closed tenant', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $tenant = activeTenant(TenantStatus::Closed);

    app(PromoteToOwner::class)->handle(platformAdmin(), $tenant, tenantUser($tenant, [SystemRole::Admin]), 'Owner left the company');
})->throws(AuthorizationException::class);

it('refuses deactivated users and users who already are owners', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $tenant = activeTenant();
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $disabled = tenantUser($tenant, [SystemRole::Admin]);
    $disabled->forceFill(['disabled_at' => now()])->saveQuietly();

    expect(fn () => app(PromoteToOwner::class)->handle(platformAdmin(), $tenant, $owner, 'Owner left the company'))
        ->toThrow(OwnerPromotionNotAllowedException::class)
        ->and(fn () => app(PromoteToOwner::class)->handle(platformAdmin(), $tenant, $disabled, 'Owner left the company'))
        ->toThrow(OwnerPromotionNotAllowedException::class)
        ->and(hasOwnerRole($disabled))->toBeFalse();
});

it('never promotes a user of another tenant (isolation)', function (): void {
    Notification::fake();
    app(ReauthenticationWindow::class)->confirm();
    $a = activeTenant();
    $foreign = tenantUser(activeTenant(), [SystemRole::Admin]);

    expect(fn () => app(PromoteToOwner::class)->handle(platformAdmin(), $a, $foreign, 'Wrong tenant on purpose'))
        ->toThrow(ModelNotFoundException::class);

    expect(hasOwnerRole($foreign))->toBeFalse()
        ->and(AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::OwnerPromoted->value)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

// --- Promote to owner: the Users list ---------------------------------------

it('offers "Make owner" only for active non-owners, to superadmins, in open tenants', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $admin = tenantUser($tenant, [SystemRole::Admin]);
    $disabled = tenantUser($tenant, [SystemRole::Viewer]);
    $disabled->forceFill(['disabled_at' => now()])->saveQuietly();

    ownershipUsersManager($tenant)
        ->assertActionVisible(TestAction::make('promoteOwner')->table($admin))
        ->assertActionHidden(TestAction::make('promoteOwner')->table($owner))
        ->assertActionHidden(TestAction::make('promoteOwner')->table($disabled));

    actingAsPlatformAdmin(platformAdmin(superadmin: false));
    ownershipUsersManager($tenant)->assertActionHidden(TestAction::make('promoteOwner')->table($admin));

    actingAsPlatformAdmin(platformAdmin());
    $closed = activeTenant(TenantStatus::Closed);
    $closedUser = tenantUser($closed, [SystemRole::Admin]);
    ownershipUsersManager($closed)->assertActionHidden(TestAction::make('promoteOwner')->table($closedUser));
});

it('makes a user owner from the Users list with a reason and the admin password', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();
    $admin = tenantUser($tenant, [SystemRole::Admin]);

    ownershipUsersManager($tenant)
        ->callAction(TestAction::make('promoteOwner')->table($admin), ['reason' => 'short'])
        ->assertHasActionErrors(['reason']);

    ownershipUsersManager($tenant)
        ->callAction(TestAction::make('promoteOwner')->table($admin), ['reason' => 'Owner left the company', 'current_password' => 'wrong-password'])
        ->assertHasActionErrors(['current_password']);

    expect(hasOwnerRole($admin))->toBeFalse();

    ownershipUsersManager($tenant)
        ->callAction(TestAction::make('promoteOwner')->table($admin), ['reason' => 'Owner left the company', 'current_password' => 'password-for-tests'])
        ->assertHasNoActionErrors();

    expect(hasOwnerRole($admin))->toBeTrue();
    Notification::assertSentTo($admin, OwnerGrantedByPlatformNotification::class);
});

it('cannot reach another tenant user through a tenant Users list', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $a = activeTenant();
    tenantUser($a, [SystemRole::Admin]);
    $foreign = tenantUser(activeTenant(), [SystemRole::Admin]);

    ownershipUsersManager($a)
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertActionHidden(TestAction::make('promoteOwner')->table($foreign));

    expect(hasOwnerRole($foreign))->toBeFalse();
});
