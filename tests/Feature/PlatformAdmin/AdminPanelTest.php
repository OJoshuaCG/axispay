<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('serves the sign-in page on the admin host', function (): void {
    get(adminUrl('/'))->assertRedirect(adminUrl('/login'));
    get(adminUrl('/login'))->assertOk();
});

it('forces every platform admin to set up 2FA', function (): void {
    actingAs(platformAdmin(twoFactor: false), 'platform');

    get(adminUrl('/'))->assertRedirectContains('/multi-factor-authentication/set-up');
    get(adminUrl('/tenants'))->assertRedirectContains('/multi-factor-authentication/set-up');
});

it('opens the panel once 2FA is enabled', function (): void {
    actingAs(platformAdmin(), 'platform');

    get(adminUrl('/'))->assertOk();
    get(adminUrl('/tenants'))->assertOk();
    get(adminUrl('/audit-logs'))->assertOk();
    get(adminUrl('/platform-admins'))->assertOk();
});

it('keeps guards and hosts separate', function (): void {
    actingAs(tenantUser(), 'web');
    get(adminUrl('/'))->assertRedirect(adminUrl('/login'));

    auth('web')->logout();
    actingAs(platformAdmin(), 'platform');
    get(appUrl('/'))->assertRedirect(appUrl('/login'));
});

it('lets support staff look but not create tenants', function (): void {
    actingAs(platformAdmin(superadmin: false), 'platform');

    get(adminUrl('/tenants'))->assertOk();
    get(adminUrl('/tenants/create'))->assertForbidden();
    get(adminUrl('/platform-admins'))->assertForbidden();
});

it('creates a tenant from the panel through the CreateTenant action', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'legal_name' => 'Panel Co S.A.',
            'display_name' => 'Panel Co',
            'timezone' => 'America/Mexico_City',
            'default_locale' => 'es',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tenant::query()->where('display_name', 'Panel Co')->sole()->status)->toBe(TenantStatus::PendingOnboarding)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::TenantCreated->value)->count())->toBe(1);
});

it('changes the tenant status from the view page', function (): void {
    Notification::fake();
    actingAsPlatformAdmin(platformAdmin());
    $tenant = activeTenant();

    Livewire::test(ViewTenant::class, ['record' => $tenant->id])
        ->callAction('changeStatus', ['status' => 'grace', 'reason' => 'Late payment'])
        ->assertHasNoActionErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Grace);
});
