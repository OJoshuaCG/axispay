<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branding\Actions\RemovePlatformFavicon;
use App\Modules\Branding\Actions\UpdatePlatformFavicon;
use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Branding\Enums\ImageRejection;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Filament\Pages\BrandingSettings;
use App\Modules\Branding\Models\PlatformFavicon;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BrandingTestHelpers as Images;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * The platform favicon (ADR-0053 amendment): uploaded on the Branding page
 * by holders of `platform:branding:manage`, after re-authentication, audited;
 * three sizes served cookie-less and versioned on the admin, app and pay
 * hosts; linked from every layout; the default favicon without an upload.
 */

beforeEach(function (): void {
    startSession();
    Notification::fake();
});

/**
 * @return list<AuditLog>
 */
function faviconAudit(AuditAction $action): array
{
    return array_values(AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', $action->value)->get()->all());
}

/**
 * The favicon <link> tags a page expects for the stored rows.
 *
 * @return list<string>
 */
function faviconTags(): array
{
    $brand = app(PlatformBrand::class);

    return [
        '<link rel="icon" type="image/png" sizes="32x32" href="'.$brand->faviconUrl(FaviconSize::Tab).'">',
        '<link rel="icon" type="image/png" sizes="192x192" href="'.$brand->faviconUrl(FaviconSize::Android).'">',
        '<link rel="apple-touch-icon" sizes="180x180" href="'.$brand->faviconUrl(FaviconSize::AppleTouch).'">',
    ];
}

function defaultFaviconTag(): string
{
    return '<link rel="icon" href="/favicon.ico" sizes="any">';
}

// --- Upload and remove -----------------------------------------------------

it('stores the three sizes, each with its own version, and audits the upload', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();

    $rows = app(UpdatePlatformFavicon::class)->handle($admin, Images::png(100, 50));

    expect(array_map(static fn (PlatformFavicon $row): int => $row->size->value, $rows))->toBe([32, 180, 192])
        ->and(array_unique(array_map(static fn (PlatformFavicon $row): string => $row->version, $rows)))->toHaveCount(3)
        ->and(PlatformFavicon::query()->count())->toBe(3);

    foreach ($rows as $row) {
        $info = getimagesizefromstring($row->content);
        expect($row->mime_type)->toBe('image/png')
            ->and($info !== false ? [$info[0], $info[1]] : null)->toBe([$row->size->value, $row->size->value])
            ->and($row->sha256)->toBe(hash('sha256', $row->content))
            ->and($row->uploaded_by_platform_admin_id)->toBe($admin->id)
            ->and(array_key_exists('content', $row->toArray()))->toBeFalse();
    }

    $entries = faviconAudit(AuditAction::PlatformFaviconUpdated);
    expect($entries)->toHaveCount(1);
    expect($entries[0]->tenant_id)->toBeNull()
        ->and($entries[0]->actor_type)->toBe(ActorType::PlatformAdmin)
        ->and($entries[0]->actor_id)->toBe($admin->id)
        ->and(is_array($sizes = $entries[0]->changes['sizes'] ?? null) ? array_map('strval', array_keys($sizes)) : [])->toEqualCanonicalizing(['32', '180', '192']);

    expect(app(PlatformBrand::class)->hasFavicon())->toBeTrue();
});

it('gives every replacement new versions, so the URLs change', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();

    app(UpdatePlatformFavicon::class)->handle($admin, Images::png(64, 64));
    $first = app(PlatformBrand::class)->faviconUrl(FaviconSize::Tab);
    app(UpdatePlatformFavicon::class)->handle($admin, Images::png(96, 96));

    expect(PlatformFavicon::query()->count())->toBe(3)
        ->and(app(PlatformBrand::class)->faviconUrl(FaviconSize::Tab) !== $first)->toBeTrue();
});

it('refuses an invalid favicon and stores nothing', function (string $bytes, ImageRejection $rejection): void {
    app(ReauthenticationWindow::class)->confirm();

    $e = thrownBy(InvalidImageException::class, static fn () => app(UpdatePlatformFavicon::class)->handle(platformAdmin(), $bytes));

    expect($e->rejection)->toBe($rejection)
        ->and(PlatformFavicon::query()->count())->toBe(0)
        ->and(faviconAudit(AuditAction::PlatformFaviconUpdated))->toBe([]);
})->with([
    'svg' => fn (): array => [Images::svg(), ImageRejection::UnsupportedType],
    'ico' => fn (): array => [Images::ico(), ImageRejection::UnsupportedType],
    'too small' => fn (): array => [Images::png(16, 16), ImageRejection::DimensionsTooSmall],
    'too large' => fn (): array => [Images::png(2001, 64), ImageRejection::DimensionsTooLarge],
    'too heavy' => fn (): array => [Images::png(64, 64).str_repeat("\0", 1_048_577), ImageRejection::TooLarge],
]);

it('guards upload and removal with the permission and the re-authentication', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    expect(fn () => app(UpdatePlatformFavicon::class)->handle(platformAdmin(superadmin: false), Images::png(64, 64)))
        ->toThrow(AuthorizationException::class);

    Images::storeFavicon();
    expect(fn () => app(RemovePlatformFavicon::class)->handle(platformAdmin(superadmin: false)))
        ->toThrow(AuthorizationException::class);

    session()->forget(ReauthenticationWindow::SESSION_KEY);
    expect(fn () => app(UpdatePlatformFavicon::class)->handle(platformAdmin(), Images::png(64, 64)))
        ->toThrow(ReauthenticationRequiredException::class)
        ->and(fn () => app(RemovePlatformFavicon::class)->handle(platformAdmin()))
        ->toThrow(ReauthenticationRequiredException::class);

    expect(PlatformFavicon::query()->count())->toBe(3)
        ->and(Gate::forUser(tenantUser())->allows('manage', PlatformFavicon::class))->toBeFalse();
});

it('removes every size, audits it once, and goes back to the default favicon', function (): void {
    app(ReauthenticationWindow::class)->confirm();
    $admin = platformAdmin();
    Images::storeFavicon();

    expect(app(RemovePlatformFavicon::class)->handle($admin))->toBeTrue()
        ->and(PlatformFavicon::query()->count())->toBe(0)
        ->and(app(PlatformBrand::class)->hasFavicon())->toBeFalse()
        ->and(app(PlatformBrand::class)->faviconUrl(FaviconSize::Tab))->toBeNull();

    $entries = faviconAudit(AuditAction::PlatformFaviconRemoved);
    expect($entries)->toHaveCount(1);
    expect($entries[0]->tenant_id)->toBeNull()->and($entries[0]->actor_id)->toBe($admin->id);

    // Nothing left: a no-op, not audited.
    expect(app(RemovePlatformFavicon::class)->handle($admin))->toBeFalse()
        ->and(faviconAudit(AuditAction::PlatformFaviconRemoved))->toHaveCount(1);
});

// --- Serving ---------------------------------------------------------------

it('serves each size on every host with safe cache headers and no cookies', function (string $host): void {
    $rows = Images::storeFavicon();

    foreach ($rows as $row) {
        $response = get('http://'.config()->string("axispay.surfaces.{$host}").Images::faviconUrl($row))->assertOk();

        expect($response->getContent())->toBe($row->content)
            ->and($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and(str_contains((string) $response->headers->get('Cache-Control'), 'max-age=31536000'))->toBeTrue()
            ->and(str_contains((string) $response->headers->get('Cache-Control'), 'immutable'))->toBeTrue()
            ->and($response->headers->getCookies())->toBe([]);
    }
})->with(['admin', 'app', 'pay']);

it('answers 404 for an old version, an unknown size or no favicon', function (): void {
    $stale = strtolower((string) Str::ulid());
    get(adminUrl("/branding/favicon/32/{$stale}.png"))->assertNotFound();

    $rows = Images::storeFavicon();

    get(adminUrl("/branding/favicon/32/{$stale}.png"))->assertNotFound();
    get(adminUrl('/branding/favicon/64/'.$rows[32]->version.'.png'))->assertNotFound();
    get(adminUrl('/branding/favicon/180/'.$rows[32]->version.'.png'))->assertNotFound();
    get(apiUrl(Images::faviconUrl($rows[32])))->assertNotFound();
});

// --- Link tags in every layout ---------------------------------------------

it('links the default favicon everywhere when none was uploaded', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'en']));

    foreach ([
        (string) get(adminUrl('/login'))->assertOk()->getContent(),
        (string) get(appUrl('/login'))->assertOk()->getContent(),
        (string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent(),
        (string) get(payUrl('/anything'), ['User-Agent' => 'Mozilla/5.0'])->assertNotFound()->getContent(),
        view('welcome')->render(),
    ] as $html) {
        expect(str_contains($html, defaultFaviconTag()))->toBeTrue()
            ->and(str_contains($html, '/branding/favicon/'))->toBeFalse();
    }
});

it('links the uploaded favicon in the panels, the checkout, its error pages and the plain layout', function (): void {
    Images::storeFavicon();
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'en']));

    $pages = [
        'admin sign-in' => (string) get(adminUrl('/login'))->assertOk()->getContent(),
        'app sign-in' => (string) get(appUrl('/login'))->assertOk()->getContent(),
        'checkout' => (string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent(),
        'pay error page' => (string) get(payUrl('/anything'), ['User-Agent' => 'Mozilla/5.0'])->assertNotFound()->getContent(),
        'plain layout' => view('welcome')->render(),
    ];

    foreach ($pages as $page => $html) {
        foreach (faviconTags() as $tag) {
            expect(str_contains($html, $tag))->toBeTrue("{$page} misses {$tag}");
        }

        expect(str_contains($html, defaultFaviconTag()))->toBeFalse("{$page} still links the default favicon");
    }
});

it('links the favicon on signed-in panel pages too', function (): void {
    Images::storeFavicon();

    \Pest\Laravel\actingAs(platformAdmin(), 'platform');
    $html = (string) get(adminUrl('/'))->assertOk()->getContent();

    expect(str_contains($html, faviconTags()[0]))->toBeTrue();
});

// --- Branding page ---------------------------------------------------------

it('uploads and removes the favicon from the Branding page, with a preview', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(BrandingSettings::class)
        ->assertSee(__('branding.favicon.heading'))
        ->assertSee(__('branding.favicon.default_in_use'))
        ->assertActionHidden('removeFavicon')
        ->callAction('uploadFavicon', data: ['favicon' => UploadedFile::fake()->createWithContent('icon.png', Images::png(64, 64))])
        ->assertHasNoActionErrors()
        ->assertSee(__('branding.favicon.size.32', ['px' => 32]))
        ->assertActionVisible('removeFavicon');

    expect(PlatformFavicon::query()->count())->toBe(3);

    Livewire::test(BrandingSettings::class)
        ->callAction('removeFavicon')
        ->assertHasNoActionErrors()
        ->assertSee(__('branding.favicon.default_in_use'));

    expect(PlatformFavicon::query()->count())->toBe(0);
});

it('refuses SVG, ICO and too-small favicons on the upload field', function (string $name, string $bytes): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(BrandingSettings::class)
        ->callAction('uploadFavicon', data: ['favicon' => UploadedFile::fake()->createWithContent($name, $bytes)])
        ->assertHasActionErrors(['favicon']);

    expect(PlatformFavicon::query()->count())->toBe(0);
})->with([
    'svg' => fn (): array => ['icon.svg', Images::svg()],
    'svg named .png' => fn (): array => ['icon.png', Images::svg()],
    'ico' => fn (): array => ['favicon.ico', Images::ico()],
    'ico named .png' => fn (): array => ['icon.png', Images::ico()],
    'too small' => fn (): array => ['icon.png', Images::png(16, 16)],
]);

it('asks for the password before changing the favicon', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    session()->forget(ReauthenticationWindow::SESSION_KEY);

    Livewire::test(BrandingSettings::class)
        ->callAction('uploadFavicon', data: ['favicon' => UploadedFile::fake()->createWithContent('icon.png', Images::png(64, 64))])
        ->assertHasActionErrors(['current_password' => 'required']);

    expect(PlatformFavicon::query()->count())->toBe(0);
});

it('has the favicon strings and audit labels in English and Spanish', function (string $locale): void {
    app()->setLocale($locale);

    foreach (['heading', 'description', 'default_in_use', 'upload', 'upload_heading', 'upload_help', 'file_help', 'remove', 'remove_heading', 'remove_help', 'updated', 'removed', 'preview_alt', 'size.32', 'size.180', 'size.192'] as $key) {
        expect(trans()->hasForLocale('branding.favicon.'.$key, $locale))->toBeTrue("Missing {$locale}: branding.favicon.{$key}");
    }

    expect(trans()->hasForLocale('branding.errors.dimensions_too_small', $locale))->toBeTrue()
        ->and(str_starts_with(AuditAction::PlatformFaviconUpdated->label(), 'audit.'))->toBeFalse()
        ->and(str_starts_with(AuditAction::PlatformFaviconRemoved->label(), 'audit.'))->toBeFalse();
})->with(['en', 'es']);
