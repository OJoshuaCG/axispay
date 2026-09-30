<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branding\Actions\RemoveTenantLogo;
use App\Modules\Branding\Actions\UpdateTenantLogo;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Filament\Pages\TenantBrandingSettings;
use App\Modules\Branding\Models\TenantLogo;
use App\Modules\Branding\Services\TenantLogos;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\BrandingTestHelpers as Images;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * Settings → Brand in the tenant panel (ADR-0056 part B): the company logo
 * shown to payers on the payment pages, a light variant and an optional dark
 * one, each uploaded and removed on its own, managed with `settings:manage`
 * (plan 17.1), checked and re-encoded like the platform logo, audited with
 * metadata only, read-only for suspended or closed tenants and during
 * impersonation, and never visible to another tenant.
 */

beforeEach(function (): void {
    startSession();
    Notification::fake();
});

function tenantLogoUpload(string $bytes, string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * @return array<string, TenantLogo> variant => logo (with its bytes)
 */
function tenantLogoRows(Tenant $tenant): array
{
    return app(TenantContext::class)->runAsTenant($tenant->id, false, static function (): array {
        $rows = [];

        foreach (TenantLogo::query()->get() as $logo) {
            $rows[$logo->variant->value] = $logo;
        }

        return $rows;
    });
}

/**
 * @return list<AuditLog>
 */
function tenantLogoAudit(Tenant $tenant, AuditAction $action): array
{
    return array_values(AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('action', $action->value)->orderBy('id')->get()->all());
}

// --- Permission and access ------------------------------------------------------

it('lets owners and admins manage the logo through settings:manage (plan 17.1)', function (): void {
    expect(SystemRole::Owner->permissions())->toContain(TenantPermission::SettingsManage)
        ->and(SystemRole::Admin->permissions())->toContain(TenantPermission::SettingsManage)
        ->and(SystemRole::Finance->permissions())->not->toContain(TenantPermission::SettingsManage)
        ->and(SystemRole::IntegrationManager->permissions())->not->toContain(TenantPermission::SettingsManage)
        ->and(SystemRole::LinkCreator->permissions())->not->toContain(TenantPermission::SettingsManage)
        ->and(SystemRole::Viewer->permissions())->not->toContain(TenantPermission::SettingsManage)
        ->and(TenantPermission::SettingsManage->isSensitive())->toBeFalse();
});

it('opens for owners in English and Spanish, explaining that only payers see the logo', function (string $locale): void {
    app()->setLocale($locale);
    actingAsTenantUser(tenantUser(activeTenant()));

    Livewire::test(TenantBrandingSettings::class)
        ->assertOk()
        ->assertSee(__('branding.tenant.title'))
        ->assertSee(__('branding.tenant.description'))
        ->assertSee(__('branding.tenant.preview_empty'))
        ->assertActionVisible('uploadLight')
        ->assertActionHidden('uploadDark')
        ->assertActionHidden('removeLight')
        ->assertActionHidden('removeDark');
})->with(['en', 'es']);

it('is only reachable with settings:manage', function (): void {
    $tenant = activeTenant();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    expect(TenantBrandingSettings::canAccess())->toBeFalse();
    get(appUrl('/settings/branding'))->assertForbidden();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Admin]));
    expect(TenantBrandingSettings::canAccess())->toBeTrue();
    get(appUrl('/settings/branding'))->assertOk()->assertSee(__('branding.tenant.title'));
});

it('is not a page of the platform panel', function (): void {
    actingAsTenantUser(tenantUser(activeTenant()));

    // The admin host has its own guard: a tenant user is sent to its sign-in.
    get(adminUrl('/settings/branding'))->assertRedirect(adminUrl('/login'));
});

// --- Upload ------------------------------------------------------------------------

it('uploads the logo re-encoded to fit 800 × 240, previews it and audits metadata only', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantBrandingSettings::class)
        ->callAction('uploadLight', data: ['logo' => tenantLogoUpload(Images::jpegWithExif(1600, 900), 'logo.jpg')])
        ->assertHasNoActionErrors()
        ->assertSee('data:image/png;base64,', false)
        ->assertSee(__('branding.tenant.dark_fallback'))
        ->assertActionVisible('uploadDark')
        ->assertActionVisible('removeLight')
        ->assertActionHidden('removeDark');

    $logo = tenantLogoRows($tenant)['light'];
    [$audit] = tenantLogoAudit($tenant, AuditAction::TenantLogoUpdated);

    expect($logo->mime_type)->toBe('image/png')
        ->and([$logo->width, $logo->height])->toBe([427, 240])
        ->and(str_starts_with($logo->content, "\x89PNG\r\n\x1A\n"))->toBeTrue()
        ->and(str_contains($logo->content, Images::EXIF_MARKER))->toBeFalse()
        ->and($logo->size_bytes)->toBe(strlen($logo->content))
        ->and($logo->sha256)->toBe(hash('sha256', $logo->content))
        ->and($logo->version)->toMatch('/^[0-9a-z]{26}$/')
        ->and($logo->uploaded_by_user_id)->toBe($owner->id)
        ->and(array_key_exists('content', $logo->toArray()))->toBeFalse()
        ->and($audit->changes)->toMatchArray([
            'variant' => 'light',
            'width' => 427,
            'height' => 240,
            'size_bytes' => $logo->size_bytes,
            'sha256_hash' => $logo->sha256,
            'previous_sha256_hash' => null,
        ])
        ->and(json_encode($audit->changes))->not->toContain(base64_encode(substr($logo->content, 0, 24)));
});

it('never enlarges a small logo', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantBrandingSettings::class)
        ->callAction('uploadLight', data: ['logo' => tenantLogoUpload(Images::png(300, 100))])
        ->assertHasNoActionErrors();

    $logo = tenantLogoRows($tenant)['light'];

    expect([$logo->width, $logo->height])->toBe([300, 100]);
});

it('replacing the logo gets a new version and records the previous fingerprint', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));

    $first = app(UpdateTenantLogo::class)->handle($owner, LogoVariant::Light, Images::png(40, 12));
    $second = app(UpdateTenantLogo::class)->handle($owner, LogoVariant::Light, Images::png(80, 24));
    $audits = tenantLogoAudit($tenant, AuditAction::TenantLogoUpdated);

    expect(tenantLogoRows($tenant))->toHaveCount(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->version)->not->toBe($first->version)
        ->and($audits)->toHaveCount(2)
        ->and($audits[1]->changes['previous_sha256_hash'] ?? null)->toBe($first->sha256);
});

it('refuses a disguised or unsupported file on the upload field', function (string $name, string $bytes): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantBrandingSettings::class)
        ->callAction('uploadLight', data: ['logo' => tenantLogoUpload($bytes, $name)])
        ->assertHasActionErrors(['logo']);

    expect(tenantLogoRows($tenant))->toBe([])
        ->and(tenantLogoAudit($tenant, AuditAction::TenantLogoUpdated))->toBe([]);
})->with([
    'svg named .png' => fn (): array => ['logo.png', Images::svg()],
    'svg' => fn (): array => ['logo.svg', Images::svg()],
    'gif' => fn (): array => ['logo.png', Images::gif()],
    'html named .jpg' => fn (): array => ['logo.jpg', '<html><script>alert(1)</script></html>'],
    'too large' => fn (): array => ['logo.png', Images::png().str_repeat("\0", 1_048_577)],
    'too many pixels' => fn (): array => ['logo.png', Images::png(2001, 10)],
]);

it('adds an optional dark variant only once a logo exists, and previews it', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    Images::storeTenantLogo($tenant);

    Livewire::test(TenantBrandingSettings::class)
        ->assertSee(__('branding.tenant.dark_fallback'))
        ->callAction('uploadDark', data: ['logo' => tenantLogoUpload(Images::webp(60, 20), 'logo-dark.webp')])
        ->assertHasNoActionErrors()
        ->assertDontSee(__('branding.tenant.dark_fallback'))
        ->assertActionVisible('removeDark');

    $rows = tenantLogoRows($tenant);

    expect(array_keys($rows))->toEqualCanonicalizing(['light', 'dark'])
        ->and($rows['dark']->mime_type)->toBe('image/png')
        ->and([$rows['dark']->width, $rows['dark']->height])->toBe([60, 20])
        ->and(tenantLogoAudit($tenant, AuditAction::TenantLogoUpdated)[0]->changes['variant'] ?? null)->toBe('dark');
});

// --- Removing ------------------------------------------------------------------------

it('removes the dark variant alone and keeps the logo', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    Images::storeTenantLogo($tenant);
    $dark = Images::storeTenantLogo($tenant, LogoVariant::Dark);

    Livewire::test(TenantBrandingSettings::class)
        ->callAction('removeDark')
        ->assertHasNoActionErrors()
        ->assertActionHidden('removeDark')
        ->assertActionVisible('removeLight');

    [$audit] = tenantLogoAudit($tenant, AuditAction::TenantLogoRemoved);

    expect(array_keys(tenantLogoRows($tenant)))->toBe(['light'])
        ->and($audit->changes)->toMatchArray(['variant' => 'dark', 'sha256_hash' => $dark->sha256, 'size_bytes' => $dark->size_bytes]);
});

it('removes the logo together with its dark variant, audits each once, and the payment page falls back to the name', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    Images::storeTenantLogo($tenant);
    Images::storeTenantLogo($tenant, LogoVariant::Dark);

    Livewire::test(TenantBrandingSettings::class)
        ->callAction('removeLight')
        ->assertHasNoActionErrors()
        ->assertSee(__('branding.tenant.preview_empty'))
        ->assertActionHidden('uploadDark');

    expect(tenantLogoRows($tenant))->toBe([])
        ->and(app(TenantLogos::class)->hasLogo($tenant->id))->toBeFalse()
        ->and(tenantLogoAudit($tenant, AuditAction::TenantLogoRemoved))->toHaveCount(2);

    // Removing what is not set changes nothing.
    expect(app(RemoveTenantLogo::class)->handle($owner, LogoVariant::Light))->toBe([])
        ->and(tenantLogoAudit($tenant, AuditAction::TenantLogoRemoved))->toHaveCount(2);
});

// --- Read-only states --------------------------------------------------------------

it('is read-only for a suspended or closed tenant', function (TenantStatus $status): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    Images::storeTenantLogo($tenant);
    $tenant->forceFill(['status' => $status])->save();

    Livewire::test(TenantBrandingSettings::class)
        ->assertOk()
        ->assertActionHidden('uploadLight')
        ->assertActionHidden('removeLight');

    expect(fn () => app(UpdateTenantLogo::class)->handle($owner, LogoVariant::Light, Images::png()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveTenantLogo::class)->handle($owner, LogoVariant::Light))->toThrow(AuthorizationException::class);
    expect(tenantLogoRows($tenant))->toHaveCount(1);
})->with([TenantStatus::Suspended, TenantStatus::Closed]);

it('refuses changes during impersonation (plan 17.4)', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    request()->setLaravelSession(app('session.store'));
    app(ImpersonationState::class)->start('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', '01J8Z3Q6T4Y0V8KX2M1N5P7R9T');

    expect($owner->can('viewAny', TenantLogo::class))->toBeTrue()
        ->and($owner->can('manage', TenantLogo::class))->toBeFalse();
    expect(fn () => app(UpdateTenantLogo::class)->handle($owner, LogoVariant::Light, Images::png()))->toThrow(AuthorizationException::class);
    expect(tenantLogoRows($tenant))->toBe([]);
});

// --- Isolation (rules.md rule 3) ---------------------------------------------------

it('never shows, replaces or removes another tenant\'s logo', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    $bBytes = Images::png(50, 15);
    $bLogo = Images::storeTenantLogo($b, bytes: $bBytes, width: 50, height: 15);
    Images::storeTenantLogo($b, LogoVariant::Dark);

    $intruder = actingAsTenantUser(tenantUser($a));

    Livewire::test(TenantBrandingSettings::class)
        ->assertDontSee(base64_encode($bBytes), false)
        ->assertSee(__('branding.tenant.preview_empty'))
        ->assertActionHidden('removeLight')
        ->assertActionHidden('removeDark')
        ->callAction('uploadLight', data: ['logo' => tenantLogoUpload(Images::png(30, 10))])
        ->assertHasNoActionErrors();

    // Removing from tenant A's panel only ever touches tenant A's rows.
    app(RemoveTenantLogo::class)->handle($intruder, LogoVariant::Dark);

    $bRows = tenantLogoRows($b);

    expect(tenantLogoRows($a))->toHaveCount(1)
        ->and(array_keys($bRows))->toEqualCanonicalizing(['light', 'dark'])
        ->and($bRows['light']->version)->toBe($bLogo->version)
        ->and($bRows['light']->content)->toBe($bBytes)
        ->and(tenantLogoAudit($b, AuditAction::TenantLogoUpdated))->toBe([])
        ->and(tenantLogoAudit($b, AuditAction::TenantLogoRemoved))->toBe([]);

    // Reading tenant B's logos from tenant A's context finds nothing.
    expect(app(TenantLogos::class)->all($b->id))->toBe([])
        ->and(app(TenantLogos::class)->content($b->id, LogoVariant::Light))->toBeNull();
});
