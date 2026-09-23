<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Identity\Filament\Resources\Users\UserResource;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Tenancy\Enums\TenantStatus;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\startSession;

it('serves the sign-in page on the app host in English and Spanish', function (): void {
    get(appUrl('/'))->assertRedirect(appUrl('/login'));
    get(appUrl('/login?lang=en'))->assertOk()->assertSee('Sign in');
    get(appUrl('/login?lang=es'))->assertOk()->assertSee('Entre a su cuenta')->assertSee('lang="es"', false);
});

it('renders the dark-mode bridge, the theme and the language switcher', function (): void {
    get(appUrl('/login'))
        ->assertOk()
        ->assertSee('MutationObserver', false)
        ->assertSee("root.setAttribute('data-theme', theme)", false)
        ->assertSee('/build/assets/theme-', false)
        ->assertSee(route('locale.update'), false);
});

it('requires 2FA for users with sensitive permissions only', function (): void {
    actingAs(tenantUser(roles: [SystemRole::Owner], twoFactor: false), 'web');
    get(appUrl('/'))->assertRedirectContains('/multi-factor-authentication/set-up');

    auth('web')->logout();
    actingAs(tenantUser(roles: [SystemRole::Viewer], twoFactor: false), 'web');
    get(appUrl('/'))->assertOk();
});

it('shows the test/live selector and switches mode, audited', function (): void {
    $owner = tenantUser();
    actingAs($owner, 'web');

    get(appUrl('/'))->assertOk()->assertSee(__('tenancy.mode.test'));

    post(appUrl('/mode'), ['livemode' => '1', 'redirect' => '/users'])->assertRedirect('/users');

    expect(session('paylink.livemode'))->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::LivemodeSwitched->value)->sole()->tenant_id)->toBe($owner->tenant_id);
});

it('shows a notice for suspended tenants', function (): void {
    actingAs(tenantUser(activeTenant(TenantStatus::Suspended), [SystemRole::Viewer], twoFactor: false), 'web');

    get(appUrl('/'))->assertOk()->assertSee(__('tenancy.banner.suspended'));
});

it('invites users from the users page', function (): void {
    Notification::fake();
    $owner = actingAsTenantUser(tenantUser());

    Livewire::test(ListUsers::class)
        ->callAction('invite', ['email' => 'panel@example.com', 'role' => 'viewer'])
        ->assertHasNoActionErrors();

    expect(UserInvitation::query()->where('email', 'panel@example.com')->exists())->toBeTrue();
});

it('asks for re-authentication before changing roles', function (): void {
    startSession();
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('changeRoles')->table($target), ['roles' => ['finance'], 'current_password' => 'wrong'])
        ->assertHasActionErrors(['current_password']);

    expect($target->refresh()->hasRole('finance'))->toBeFalse();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('changeRoles')->table($target), ['roles' => ['finance'], 'current_password' => 'password-for-tests'])
        ->assertHasNoActionErrors();

    expect($target->refresh()->hasRole('finance'))->toBeTrue();
});

it('hides user management from users without users:manage', function (): void {
    actingAsTenantUser(tenantUser(roles: [SystemRole::Finance]));

    expect(UserResource::canViewAny())->toBeFalse();
    get(appUrl('/users'))->assertForbidden();
});
