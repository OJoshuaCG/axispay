<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\EditTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers\InvitationsRelationManager;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Modules\Tenancy\Actions\InviteTenantOwner;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\get;

/*
 * ADR-0043: the tenant view page of the platform panel (edit, invitations,
 * users, closing) and its isolation: a tenant's lists never show another
 * tenant's rows, and another tenant's invitation cannot be acted on.
 */

/**
 * @return Testable<InvitationsRelationManager>
 */
function invitationsManager(Tenant $tenant): Testable
{
    return Livewire::test(InvitationsRelationManager::class, ['ownerRecord' => $tenant, 'pageClass' => ViewTenant::class]);
}

/**
 * @return Testable<UsersRelationManager>
 */
function usersManager(Tenant $tenant): Testable
{
    return Livewire::test(UsersRelationManager::class, ['ownerRecord' => $tenant, 'pageClass' => ViewTenant::class]);
}

function platformInvitation(Tenant $tenant, string $email): UserInvitation
{
    return app(InviteTenantOwner::class)->handle(platformAdmin(), $tenant, $email);
}

it('edits the tenant profile from the edit page, without the owner or status fields', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();

    Livewire::test(EditTenant::class, ['record' => $tenant->id])
        ->assertFormFieldHidden('owner_email')
        ->assertFormFieldDoesNotExist('status')
        ->fillForm(['display_name' => 'Edited Co', 'default_locale' => 'en'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->display_name)->toBe('Edited Co')
        ->and($tenant->default_locale)->toBe('en');
});

it('forbids the edit page to support staff and for closed tenants', function (): void {
    actingAsPlatformAdmin(platformAdmin(superadmin: false));
    get(adminUrl('/tenants/'.activeTenant()->id.'/edit'))->assertForbidden();

    actingAsPlatformAdmin(platformAdmin());
    get(adminUrl('/tenants/'.activeTenant(TenantStatus::Closed)->id.'/edit'))->assertForbidden();
});

it('renders the tenant view with its relation managers', function (): void {
    actingAsPlatformAdmin(platformAdmin());

    get(adminUrl('/tenants/'.activeTenant()->id))
        ->assertOk()
        ->assertSee(__('platform.tenants.invitations.title'))
        ->assertSee(__('platform.tenants.users.title'))
        ->assertSee(__('platform.tenants.fields.status_help'));
});

it('lists only the viewed tenant invitations, with their derived status', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    [$a, $b] = [activeTenant(), activeTenant()];
    $own = platformInvitation($a, 'own@a.test');
    $foreign = platformInvitation($b, 'foreign@b.test');

    invitationsManager($a)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee('own@a.test')
        ->assertSee(InvitationStatus::Pending->label())
        ->assertDontSee('foreign@b.test');
});

it('invites an owner from the invitations list', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant(TenantStatus::PendingOnboarding);

    invitationsManager($tenant)
        ->callAction(TestAction::make('inviteOwner')->table(), ['email' => 'panel@owner.test'])
        ->assertHasNoActionErrors()
        ->assertSee('panel@owner.test');
});

it('resends and revokes from the list', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();
    $toResend = platformInvitation($tenant, 'resend@panel.test');
    $toRevoke = platformInvitation($tenant, 'revoke@panel.test');
    $hash = $toResend->token_hash;

    invitationsManager($tenant)
        ->callAction(TestAction::make('resend')->table($toResend))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('revoke')->table($toRevoke))
        ->assertHasNoActionErrors();

    expect($toResend->refresh()->token_hash)->not->toBe($hash)
        ->and($toRevoke->refresh()->status())->toBe(InvitationStatus::Revoked);
});

it('offers resend for pending or expired and revoke for pending only', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();
    $revoked = platformInvitation($tenant, 'revoked@panel.test');
    $revoked->forceFill(['revoked_at' => now()])->save();
    $expired = platformInvitation($tenant, 'expired@panel.test');
    $expired->forceFill(['expires_at' => now()->subHour()])->save();

    invitationsManager($tenant)
        ->assertActionHidden(TestAction::make('resend')->table($revoked))
        ->assertActionHidden(TestAction::make('revoke')->table($revoked))
        ->assertActionVisible(TestAction::make('resend')->table($expired))
        ->assertActionHidden(TestAction::make('revoke')->table($expired));
});

it('shows support staff the lists read-only with masked e-mails', function (): void {
    Notification::fake();
    $tenant = activeTenant();
    $invitation = platformInvitation($tenant, 'masked@panel.test');
    $user = tenantUser($tenant, [SystemRole::Owner]);
    actingAsPlatformAdmin(platformAdmin(superadmin: false));

    invitationsManager($tenant)
        ->assertCanSeeTableRecords([$invitation])
        ->assertSee('m***@panel.test')
        ->assertDontSee('masked@panel.test')
        ->assertActionHidden(TestAction::make('inviteOwner')->table())
        ->assertActionHidden(TestAction::make('resend')->table($invitation))
        ->assertActionHidden(TestAction::make('revoke')->table($invitation));

    usersManager($tenant)
        ->assertCanSeeTableRecords([$user])
        ->assertDontSee($user->email);
});

it('cannot act on another tenant invitation through a tenant page', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    [$a, $b] = [activeTenant(), activeTenant()];
    platformInvitation($a, 'own@act.test');
    $foreign = platformInvitation($b, 'foreign@act.test');

    invitationsManager($a)
        ->assertActionHidden(TestAction::make('revoke')->table($foreign))
        ->assertActionHidden(TestAction::make('resend')->table($foreign));

    expect($foreign->refresh()->status())->toBe(InvitationStatus::Pending);
});

it('lists only the viewed tenant users with roles, status and 2FA', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    [$a, $b] = [activeTenant(), activeTenant()];
    $owner = tenantUser($a, [SystemRole::Owner]);
    $viewer = tenantUser($a, [SystemRole::Viewer], twoFactor: false);
    $foreign = tenantUser($b, [SystemRole::Owner]);

    usersManager($a)
        ->assertCanSeeTableRecords([$owner, $viewer])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee($owner->email)
        ->assertSee(SystemRole::Owner->label())
        ->assertSee(SystemRole::Viewer->label())
        ->assertDontSee($foreign->email)
        ->assertTableColumnStateSet('two_factor_confirmed_at', true, $owner)
        ->assertTableColumnStateSet('two_factor_confirmed_at', false, $viewer);
});

it('requires the display name to be retyped before closing from the view page', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('changeStatus', ['status' => 'closed', 'reason' => 'Contract ended'])
        ->assertHasActionErrors(['close_confirmation' => 'required']);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('changeStatus', ['status' => 'closed', 'reason' => 'Contract ended', 'close_confirmation' => 'wrong']);

    expect($tenant->refresh()->status)->toBe(TenantStatus::Active);

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('changeStatus', ['status' => 'closed', 'reason' => 'Contract ended', 'close_confirmation' => $tenant->display_name])
        ->assertHasNoActionErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Closed);
});
