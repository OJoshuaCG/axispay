<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ListTenants;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\startSession;

/*
 * "View as user" in the platform panel (plan 17.4). Regression: a tenant
 * row opened the EDIT page (ListRecords' default record URL picks the first
 * visible view/edit table action, and the table only had EditAction), where
 * the action does not exist, so a superadmin could not find it.
 */

beforeEach(function (): void {
    startSession();
});

/**
 * @return Testable<ViewTenant>
 */
function impersonationViewPage(Tenant $tenant): Testable
{
    return Livewire::test(ViewTenant::class, ['record' => $tenant->id]);
}

/**
 * @return Testable<UsersRelationManager>
 */
function impersonationUsersTab(Tenant $tenant): Testable
{
    return Livewire::test(UsersRelationManager::class, ['ownerRecord' => $tenant, 'pageClass' => ViewTenant::class]);
}

it('opens the view page, not the edit page, when a tenant row is clicked', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant(TenantStatus::PendingOnboarding);

    $page = Livewire::test(ListTenants::class)->instance();

    expect($page)->toBeInstanceOf(ListTenants::class)
        ->and($page instanceof ListTenants ? $page->getTable()->getRecordUrl($tenant) : null)
        ->toBe(TenantResource::getUrl('view', ['record' => $tenant]));
});

it('offers "View as user" for a pending-onboarding tenant whose owner accepted the invitation', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant(TenantStatus::PendingOnboarding);
    tenantUser($tenant, [SystemRole::Owner]);

    impersonationViewPage($tenant)
        ->assertActionVisible('impersonate')
        ->assertActionEnabled('impersonate');
});

it('starts the session from the header and redirects to the hand-off link', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant(TenantStatus::PendingOnboarding);
    $owner = tenantUser($tenant, [SystemRole::Owner]);

    impersonationViewPage($tenant)
        ->callAction('impersonate', ['user_id' => $owner->id, 'reason' => 'Helping with the set-up, ticket 42'])
        ->assertHasNoActionErrors()
        ->assertRedirectContains('/impersonation/');

    expect(ImpersonationSession::query()->withoutGlobalScopes()->where('user_id', $owner->id)->exists())->toBeTrue();
});

it('disables "View as user" while the tenant has no active user', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant(TenantStatus::PendingOnboarding);
    $disabled = tenantUser($tenant, [SystemRole::Owner]);
    $disabled->forceFill(['disabled_at' => now()])->saveQuietly();

    impersonationViewPage($tenant)
        ->assertActionVisible('impersonate')
        ->assertActionDisabled('impersonate')
        ->assertSee(__('platform.impersonation.no_active_users'), false);
});

it('hides "View as user" for a closed tenant and for support staff', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $closed = activeTenant(TenantStatus::Closed);
    tenantUser($closed, [SystemRole::Owner]);

    impersonationViewPage($closed)->assertActionHidden('impersonate');

    actingAsPlatformAdmin(platformAdmin(superadmin: false));
    $tenant = activeTenant();
    tenantUser($tenant, [SystemRole::Owner]);

    impersonationViewPage($tenant)->assertActionHidden('impersonate');
});

it('offers "View as this user" on each active row of the Users tab, with the same rules', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant(TenantStatus::PendingOnboarding);
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $disabled = tenantUser($tenant, [SystemRole::Viewer]);
    $disabled->forceFill(['disabled_at' => now()])->saveQuietly();

    impersonationUsersTab($tenant)
        ->assertActionVisible(TestAction::make('impersonate')->table($owner))
        ->assertActionHidden(TestAction::make('impersonate')->table($disabled));

    impersonationUsersTab($tenant)
        ->callAction(TestAction::make('impersonate')->table($owner), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    impersonationUsersTab($tenant)
        ->callAction(TestAction::make('impersonate')->table($owner), ['reason' => 'Checking a reported screen'])
        ->assertHasNoActionErrors()
        ->assertRedirectContains('/impersonation/');

    $closed = activeTenant(TenantStatus::Closed);
    $closedUser = tenantUser($closed, [SystemRole::Owner]);
    impersonationUsersTab($closed)->assertActionHidden(TestAction::make('impersonate')->table($closedUser));

    actingAsPlatformAdmin(platformAdmin(superadmin: false));
    impersonationUsersTab($tenant)->assertActionHidden(TestAction::make('impersonate')->table($owner));
});
