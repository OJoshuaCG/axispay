<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branding\Actions\ChangeBrandDisplayMode;
use App\Modules\Branding\Actions\RemovePlatformLogo;
use App\Modules\Branding\Actions\UpdatePlatformLogo;
use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\ImageRejection;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Models\PlatformSetting;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\BrandingTestHelpers as Images;

use function Pest\Laravel\startSession;

/*
 * ADR-0053: the platform logo and the brand display mode are changed only by
 * holders of `platform:branding:manage` (superadmin), after a fresh
 * re-authentication, and every change is audited in the platform log.
 */

beforeEach(function (): void {
    startSession();
});

/**
 * @return list<AuditLog>
 */
function brandingAudit(AuditAction $action): array
{
    return array_values(AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', $action->value)->orderBy('id')->get()->all());
}

// --- Permission catalog ----------------------------------------------------

it('gives platform:branding:manage to superadmins only', function (): void {
    expect(PlatformRole::Superadmin->permissions())->toContain(PlatformPermission::BrandingManage)
        ->and(PlatformRole::SupportReadonly->permissions())->toBe([])
        ->and(platformAdmin()->hasPlatformPermission(PlatformPermission::BrandingManage))->toBeTrue()
        ->and(platformAdmin(superadmin: false)->hasPlatformPermission(PlatformPermission::BrandingManage))->toBeFalse();
});

it('takes the permission away from a disabled superadmin', function (): void {
    $admin = platformAdmin();
    $admin->forceFill(['disabled_at' => now()])->save();

    expect($admin->hasPlatformPermission(PlatformPermission::BrandingManage))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('manage', PlatformLogo::class))->toBeFalse();
});

it('never lets a tenant user manage the platform brand', function (): void {
    expect(Gate::forUser(tenantUser())->allows('manage', PlatformLogo::class))->toBeFalse();
});

// --- Upload ----------------------------------------------------------------

it('stores the re-encoded logo and audits the change in the platform log', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();

    $logo = app(UpdatePlatformLogo::class)->handle($admin, LogoVariant::Light, Images::jpegWithExif(1600, 900));

    expect($logo->variant)->toBe(LogoVariant::Light)
        ->and($logo->mime_type)->toBe('image/png')
        // The platform box (1024 × 512), not the merchant one (800 × 240).
        ->and([$logo->width, $logo->height])->toBe([910, 512])
        ->and($logo->size_bytes)->toBe(strlen($logo->content))
        ->and($logo->sha256)->toBe(hash('sha256', $logo->content))
        ->and(str_contains($logo->content, Images::EXIF_MARKER))->toBeFalse()
        ->and($logo->uploaded_by_platform_admin_id)->toBe($admin->id)
        ->and(array_key_exists('content', $logo->toArray()))->toBeFalse();

    $entries = brandingAudit(AuditAction::PlatformLogoUpdated);
    expect($entries)->toHaveCount(1);
    expect($entries[0]->tenant_id)->toBeNull()
        ->and($entries[0]->actor_type)->toBe(ActorType::PlatformAdmin)
        ->and($entries[0]->actor_id)->toBe($admin->id)
        ->and($entries[0]->subject_id)->toBe($logo->id)
        ->and($entries[0]->changes['variant'] ?? null)->toBe('light')
        ->and($entries[0]->changes['sha256_hash'] ?? null)->toBe($logo->sha256)
        ->and($entries[0]->changes['previous_sha256_hash'] ?? 'missing')->toBeNull();
});

it('gives every replacement a new version, so the served URL changes', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();
    $brand = app(PlatformBrand::class);

    $first = app(UpdatePlatformLogo::class)->handle($admin, LogoVariant::Light, Images::png(40, 12));
    $firstUrl = $brand->logoUrl();
    $second = app(UpdatePlatformLogo::class)->handle($admin, LogoVariant::Light, Images::png(60, 12));

    expect(PlatformLogo::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->version !== $first->version)->toBeTrue()
        ->and($firstUrl)->toBe(Images::url($first))
        ->and($brand->logoUrl())->toBe(Images::url($second));

    expect(brandingAudit(AuditAction::PlatformLogoUpdated)[1]->changes['previous_sha256_hash'] ?? null)->toBe($first->sha256);
});

it('refuses an invalid image and stores nothing', function (): void {
    app(ReauthenticationWindow::class)->confirm();

    $e = thrownBy(InvalidImageException::class, static fn () => app(UpdatePlatformLogo::class)->handle(platformAdmin(), LogoVariant::Light, Images::svg()));

    expect($e instanceof InvalidImageException ? $e->rejection : null)->toBe(ImageRejection::UnsupportedType)
        ->and(PlatformLogo::query()->count())->toBe(0)
        ->and(brandingAudit(AuditAction::PlatformLogoUpdated))->toBe([]);
});

it('lets only holders of the permission upload a logo', function (): void {
    app(ReauthenticationWindow::class)->confirm();

    app(UpdatePlatformLogo::class)->handle(platformAdmin(superadmin: false), LogoVariant::Light, Images::png());
})->throws(AuthorizationException::class);

it('requires a fresh re-authentication to upload a logo', function (): void {
    session()->forget(ReauthenticationWindow::SESSION_KEY);

    expect(fn () => app(UpdatePlatformLogo::class)->handle(platformAdmin(), LogoVariant::Light, Images::png()))
        ->toThrow(ReauthenticationRequiredException::class);

    expect(PlatformLogo::query()->count())->toBe(0);
});

// --- Remove ----------------------------------------------------------------

it('removes the dark variant alone, and both variants with the light one, auditing each', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();
    Images::storeLogo(LogoVariant::Light);
    Images::storeLogo(LogoVariant::Dark);

    expect(app(RemovePlatformLogo::class)->handle($admin, LogoVariant::Dark))->toBe([LogoVariant::Dark])
        ->and(app(PlatformBrand::class)->hasVariant(LogoVariant::Dark))->toBeFalse()
        ->and(app(PlatformBrand::class)->hasLogo())->toBeTrue();

    Images::storeLogo(LogoVariant::Dark);

    expect(app(RemovePlatformLogo::class)->handle($admin, LogoVariant::Light))->toEqualCanonicalizing([LogoVariant::Light, LogoVariant::Dark])
        ->and(PlatformLogo::query()->count())->toBe(0)
        ->and(app(PlatformBrand::class)->hasLogo())->toBeFalse();

    $entries = brandingAudit(AuditAction::PlatformLogoRemoved);
    expect($entries)->toHaveCount(3);

    foreach ($entries as $entry) {
        expect($entry->tenant_id)->toBeNull()
            ->and($entry->actor_type)->toBe(ActorType::PlatformAdmin)
            ->and($entry->actor_id)->toBe($admin->id);
    }
});

it('does not audit removing a logo that is not set', function (): void {
    app(ReauthenticationWindow::class)->confirm();

    expect(app(RemovePlatformLogo::class)->handle(platformAdmin(), LogoVariant::Light))->toBe([])
        ->and(brandingAudit(AuditAction::PlatformLogoRemoved))->toBe([]);
});

it('guards the removal with the permission and the re-authentication', function (): void {
    Images::storeLogo();

    app(ReauthenticationWindow::class)->confirm();
    expect(fn () => app(RemovePlatformLogo::class)->handle(platformAdmin(superadmin: false), LogoVariant::Light))
        ->toThrow(AuthorizationException::class);

    session()->forget(ReauthenticationWindow::SESSION_KEY);
    expect(fn () => app(RemovePlatformLogo::class)->handle(platformAdmin(), LogoVariant::Light))
        ->toThrow(ReauthenticationRequiredException::class);

    expect(PlatformLogo::query()->count())->toBe(1);
});

// --- Display mode ----------------------------------------------------------

it('changes the display mode and audits before and after', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();
    Images::storeLogo();

    expect(app(PlatformBrand::class)->mode())->toBe(BrandDisplayMode::LogoAndName);

    app(ChangeBrandDisplayMode::class)->handle($admin, BrandDisplayMode::LogoOnly);

    expect(app(PlatformBrand::class)->mode())->toBe(BrandDisplayMode::LogoOnly)
        ->and(PlatformSetting::query()->find(PlatformSetting::BRAND_DISPLAY_MODE)?->value)->toBe('logo_only');

    $entries = brandingAudit(AuditAction::PlatformBrandDisplayModeChanged);
    expect($entries)->toHaveCount(1);
    expect($entries[0]->tenant_id)->toBeNull()
        ->and($entries[0]->actor_id)->toBe($admin->id)
        ->and($entries[0]->changes)->toBe(['before' => 'logo_and_name', 'after' => 'logo_only']);
});

it('does not audit choosing the mode already in force', function (): void {
    app(ReauthenticationWindow::class)->confirm();

    app(ChangeBrandDisplayMode::class)->handle(platformAdmin(), BrandDisplayMode::LogoAndName);

    expect(brandingAudit(AuditAction::PlatformBrandDisplayModeChanged))->toBe([]);
});

it('guards the display mode with the permission and the re-authentication', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    expect(fn () => app(ChangeBrandDisplayMode::class)->handle(platformAdmin(superadmin: false), BrandDisplayMode::NameOnly))
        ->toThrow(AuthorizationException::class);

    session()->forget(ReauthenticationWindow::SESSION_KEY);
    expect(fn () => app(ChangeBrandDisplayMode::class)->handle(platformAdmin(), BrandDisplayMode::NameOnly))
        ->toThrow(ReauthenticationRequiredException::class);

    expect(PlatformSetting::query()->count())->toBe(0);
});

// --- Fallbacks -------------------------------------------------------------

it('shows the name whatever the mode when there is no logo', function (BrandDisplayMode $mode): void {
    Images::setMode($mode);
    $brand = app(PlatformBrand::class);

    expect($brand->mode())->toBe(BrandDisplayMode::NameOnly)
        ->and($brand->configuredMode())->toBe($mode)
        ->and($brand->showsLogo())->toBeFalse()
        ->and($brand->showsName())->toBeTrue()
        ->and($brand->logoUrl(LogoVariant::Light))->toBeNull()
        ->and($brand->logoUrl(LogoVariant::Dark))->toBeNull();
})->with(BrandDisplayMode::cases());

it('uses the light logo in dark mode when there is no dark variant', function (): void {
    $light = Images::storeLogo(LogoVariant::Light);

    expect(app(PlatformBrand::class)->logoUrl(LogoVariant::Dark))->toBe(Images::url($light));

    $dark = Images::storeLogo(LogoVariant::Dark);

    expect(app(PlatformBrand::class)->logoUrl(LogoVariant::Dark))->toBe(Images::url($dark));
});

it('never shows a dark variant without a light logo', function (): void {
    Images::storeLogo(LogoVariant::Dark);
    $brand = app(PlatformBrand::class);

    expect($brand->hasLogo())->toBeFalse()
        ->and($brand->mode())->toBe(BrandDisplayMode::NameOnly);
});

it('reads the brand from the cache, not the database, once remembered', function (): void {
    $logo = Images::storeLogo();
    expect(app(PlatformBrand::class)->logoUrl())->toBe(Images::url($logo));

    // A row changed behind the actions' back is not seen until forget().
    PlatformLogo::query()->whereKey($logo->id)->update(['version' => strtolower((string) Str::ulid())]);
    app()->forgetScopedInstances();

    expect(app(PlatformBrand::class)->logoUrl())->toBe(Images::url($logo));

    app(PlatformBrand::class)->forget();

    expect(app(PlatformBrand::class)->logoUrl())->not->toBe(Images::url($logo));
});
