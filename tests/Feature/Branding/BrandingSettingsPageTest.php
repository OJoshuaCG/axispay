<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Filament\Pages\BrandingSettings;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Models\PlatformSetting;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\BrandingTestHelpers as Images;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * The "Branding" page of the platform panel (ADR-0053): superadmins only,
 * upload with light and dark previews, remove, display mode; every change
 * asks for the re-authentication window. Tenant users never reach it.
 */

beforeEach(function (): void {
    startSession();
    Notification::fake();
});

function brandingUpload(string $bytes, string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function brandingAuditCount(AuditAction $action): int
{
    return AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', $action->value)->count();
}

// --- Access ----------------------------------------------------------------

it('opens for superadmins in English and Spanish', function (string $locale): void {
    app()->setLocale($locale);
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(BrandingSettings::class)
        ->assertOk()
        ->assertSee(__('branding.page.title'))
        ->assertSee(__('branding.logo.heading'))
        ->assertSee(__('branding.display.no_logo'))
        ->assertActionVisible('upload')
        ->assertActionVisible('displayMode')
        ->assertActionHidden('remove');
})->with(['en', 'es']);

it('is only reachable with platform:branding:manage', function (): void {
    actingAs(platformAdmin(superadmin: false), 'platform');
    get(adminUrl('/settings/branding'))->assertForbidden();

    actingAs(platformAdmin(), 'platform');
    get(adminUrl('/settings/branding'))->assertOk()->assertSee(__('branding.page.title'));
});

it('never lets a tenant user reach the page or its upload', function (): void {
    actingAsTenantUser(tenantUser());

    // Not a page of the tenant panel, and the admin host has its own guard.
    get(appUrl('/settings/branding'))->assertNotFound();
    get(adminUrl('/settings/branding'))->assertRedirect(adminUrl('/login'));

    expect(BrandingSettings::canAccess())->toBeFalse();
    Livewire::test(BrandingSettings::class)->assertForbidden();

    expect(PlatformLogo::query()->count())->toBe(0);
});

// --- Upload ----------------------------------------------------------------

it('uploads a logo and shows the light and dark previews', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(BrandingSettings::class)
        ->callAction('upload', data: ['variant' => 'light', 'logo' => brandingUpload(Images::png())])
        ->assertHasNoActionErrors()
        ->assertSee(__('branding.preview.dark_fallback'))
        ->assertActionVisible('remove');

    $logo = PlatformLogo::query()->sole();

    expect($logo->variant)->toBe(LogoVariant::Light)
        ->and(app(PlatformBrand::class)->logoUrl())->toBe(Images::url($logo))
        ->and(brandingAuditCount(AuditAction::PlatformLogoUpdated))->toBe(1);
});

it('refuses a disguised or unsupported file on the upload field', function (string $name, string $bytes): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(BrandingSettings::class)
        ->callAction('upload', data: ['variant' => 'light', 'logo' => brandingUpload($bytes, $name)])
        ->assertHasActionErrors(['logo']);

    expect(PlatformLogo::query()->count())->toBe(0)
        ->and(brandingAuditCount(AuditAction::PlatformLogoUpdated))->toBe(0);
})->with([
    'svg named .png' => fn (): array => ['logo.png', Images::svg()],
    'svg' => fn (): array => ['logo.svg', Images::svg()],
    'html named .jpg' => fn (): array => ['logo.jpg', '<html><script>alert(1)</script></html>'],
    'too large' => fn (): array => ['logo.png', Images::png().str_repeat("\0", 1_048_577)],
    'too many pixels' => fn (): array => ['logo.png', Images::png(2001, 10)],
]);

it('asks for the password when the re-authentication window is closed', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    session()->forget(ReauthenticationWindow::SESSION_KEY);

    Livewire::test(BrandingSettings::class)
        ->callAction('upload', data: ['variant' => 'light', 'logo' => brandingUpload(Images::png())])
        ->assertHasActionErrors(['current_password' => 'required']);

    Livewire::test(BrandingSettings::class)
        ->callAction('displayMode', data: ['mode' => 'logo_only'])
        ->assertHasActionErrors(['current_password' => 'required']);

    expect(PlatformLogo::query()->count())->toBe(0)
        ->and(PlatformSetting::query()->count())->toBe(0);
});

it('offers the dark variant only once a light logo exists', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(BrandingSettings::class)
        ->callAction('upload', data: ['variant' => 'dark', 'logo' => brandingUpload(Images::png())])
        ->assertHasActionErrors(['variant']);

    Images::storeLogo();

    Livewire::test(BrandingSettings::class)
        ->callAction('upload', data: ['variant' => 'dark', 'logo' => brandingUpload(Images::png())])
        ->assertHasNoActionErrors()
        ->assertDontSee(__('branding.preview.dark_fallback'));

    expect(app(PlatformBrand::class)->hasVariant(LogoVariant::Dark))->toBeTrue();
});

// --- Remove and display mode ----------------------------------------------

it('removes the logo from the page', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();
    Images::storeLogo();

    Livewire::test(BrandingSettings::class)
        ->callAction('remove', data: ['variant' => 'light'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('remove');

    expect(PlatformLogo::query()->count())->toBe(0)
        ->and(brandingAuditCount(AuditAction::PlatformLogoRemoved))->toBe(1);
});

it('changes the display mode from the page', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();
    Images::storeLogo();

    Livewire::test(BrandingSettings::class)
        ->callAction('displayMode', data: ['mode' => 'logo_only'])
        ->assertHasNoActionErrors()
        ->assertSee(BrandDisplayMode::LogoOnly->label());

    expect(app(PlatformBrand::class)->mode())->toBe(BrandDisplayMode::LogoOnly)
        ->and(brandingAuditCount(AuditAction::PlatformBrandDisplayModeChanged))->toBe(1);
});

it('hides every action from a platform admin without the permission', function (): void {
    actingAsPlatformAdmin(platformAdmin(superadmin: false));

    expect(BrandingSettings::canAccess())->toBeFalse();
});
