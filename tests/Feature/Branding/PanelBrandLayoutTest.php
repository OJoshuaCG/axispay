<?php

declare(strict_types=1);

use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Shared\Support\Brand;
use Illuminate\Support\Facades\Blade;
use Tests\Support\BrandingTestHelpers as Images;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * ADR-0054 (and ADR-0053): the brand on the simple pages (bigger, name
 * stacked under the logo) and in the full-height sidebar from lg. The sizes
 * and the stacking are CSS (resources/css/filament/theme.css, tokens in
 * resources/css/theme.css); these tests pin the markup and the contract the
 * CSS relies on: one .pl-brand per place, its kind (logo or mark), the name
 * after the logo, and the logo height taken from the context variable.
 */

/** The HTML of the first element whose opening tag contains $class, up to $end. */
function brandSlice(string $html, string $class, string $end): string
{
    $start = strpos($html, $class);
    expect($start)->not->toBeFalse();

    $stop = strpos($html, $end, (int) $start);

    return substr($html, (int) $start, ($stop === false ? strlen($html) : $stop) - (int) $start);
}

// --- Simple pages ----------------------------------------------------------

it('renders the brand above the sign-in card per display mode', function (string $url, BrandDisplayMode $mode, string $kind, bool $logo, bool $visibleName): void {
    $stored = Images::storeLogo();
    Images::setMode($mode);

    $header = brandSlice((string) get($url)->assertOk()->getContent(), 'fi-simple-header', '</header>');

    expect(str_contains($header, 'class="pl-brand" data-brand-mode="'.$mode->value.'" data-brand-kind="'.$kind.'"'))->toBeTrue()
        ->and(str_contains($header, 'src="'.Images::url($stored).'" alt="'.e(Brand::displayName()).'"'))->toBe($logo)
        ->and(str_contains($header, 'class="pl-brand-name" aria-hidden="true">'.e(Brand::displayName()).'</span>'))->toBe($visibleName)
        // The logo's box height follows the place (compact, simple, sidebar): CSS variable.
        ->and(str_contains($header, 'height: var(--pl-brand-logo-height)'))->toBeTrue();

    if ($logo && $visibleName) {
        // Stacked under the logo by CSS: the name comes after the image.
        expect(strpos($header, 'pl-brand-logo') < strpos($header, 'pl-brand-name'))->toBeTrue();
    }
})->with([
    'admin sign-in' => fn (): string => adminUrl('/login'),
    'app sign-in' => fn (): string => appUrl('/login'),
])->with([
    'logo and name' => [BrandDisplayMode::LogoAndName, 'logo', true, true],
    'logo only' => [BrandDisplayMode::LogoOnly, 'logo', true, false],
    'name only' => [BrandDisplayMode::NameOnly, 'mark', false, false],
]);

it('shows the stand-in mark and the name on the sign-in page without a logo', function (): void {
    $header = brandSlice((string) get(adminUrl('/login'))->assertOk()->getContent(), 'fi-simple-header', '</header>');

    expect(str_contains($header, 'data-brand-kind="mark"'))->toBeTrue()
        ->and(str_contains($header, 'class="pl-brand-mark"'))->toBeTrue()
        ->and(str_contains($header, 'class="pl-brand-name">'.e(Brand::displayName()).'</span>'))->toBeTrue();
});

it('renders the brand at sign-in size on the invitation pages per display mode', function (BrandDisplayMode $mode, string $kind, bool $visibleName): void {
    $stored = Images::storeLogo();
    Images::setMode($mode);

    $html = Blade::render('<x-platform-brand />');

    expect(str_contains($html, 'data-brand-mode="'.$mode->value.'" data-brand-kind="'.$kind.'"'))->toBeTrue()
        ->and(str_contains($html, 'src="'.Images::url($stored).'" alt="'.e(Brand::displayName()).'" class="brand-logo-simple'))->toBe($kind === 'logo')
        ->and(str_contains($html, 'aria-hidden="true">'.e(Brand::displayName()).'</span>'))->toBe($visibleName)
        ->and(str_contains($html, e(Brand::displayName())))->toBeTrue();

    expect(str_contains(view('identity.invitation-invalid')->render(), 'data-brand-kind="'.$kind.'"'))->toBeTrue();
})->with([
    'logo and name' => [BrandDisplayMode::LogoAndName, 'logo', true],
    'logo only' => [BrandDisplayMode::LogoOnly, 'logo', false],
    'name only' => [BrandDisplayMode::NameOnly, 'mark', false],
]);

it('swaps to the dark logo on the invitation pages only when it exists', function (): void {
    $light = Images::storeLogo(LogoVariant::Light);
    expect(str_contains(Blade::render('<x-platform-brand />'), 'dark:block'))->toBeFalse();

    $dark = Images::storeLogo(LogoVariant::Dark);
    $html = Blade::render('<x-platform-brand />');

    expect(str_contains($html, Images::url($light)))->toBeTrue()
        ->and(str_contains($html, 'src="'.Images::url($dark).'"'))->toBeTrue()
        ->and(str_contains($html, 'hidden dark:block'))->toBeTrue();
});

// --- Sidebar ---------------------------------------------------------------

it('puts the brand in the sidebar header of both panels per display mode', function (BrandDisplayMode $mode, string $kind, bool $visibleName): void {
    $stored = Images::storeLogo();
    Images::setMode($mode);

    actingAs(platformAdmin(), 'platform');
    $admin = brandSlice((string) get(adminUrl('/'))->assertOk()->getContent(), 'fi-sidebar-header', '</header>');

    actingAsTenantUser(tenantUser());
    $app = brandSlice((string) get(appUrl('/'))->assertOk()->getContent(), 'fi-sidebar-header', '</header>');

    foreach ([$admin, $app] as $header) {
        expect(str_contains($header, 'data-brand-kind="'.$kind.'"'))->toBeTrue()
            ->and(str_contains($header, 'src="'.Images::url($stored).'"'))->toBe($kind === 'logo')
            ->and(str_contains($header, 'class="pl-brand-name" aria-hidden="true"'))->toBe($visibleName)
            // The name is always there: visible, or as the logo's alt text.
            ->and(str_contains($header, e(Brand::displayName())))->toBeTrue();
    }
})->with([
    'logo and name' => [BrandDisplayMode::LogoAndName, 'logo', true],
    'logo only' => [BrandDisplayMode::LogoOnly, 'logo', false],
    'name only' => [BrandDisplayMode::NameOnly, 'mark', false],
]);

it('keeps the DOM order: skip link, topbar, then the layout with the sidebar and the main content', function (): void {
    actingAs(platformAdmin(), 'platform');
    $html = (string) get(adminUrl('/'))->assertOk()->getContent();

    $positions = array_map(static fn (string $needle): int|false => strpos($html, $needle), [
        'fi-skip-link',
        'class="fi-topbar-ctn"',
        'class="fi-layout"',
        'id="fi-main-sidebar"',
        'id="fi-main-content"',
    ]);

    expect(in_array(false, $positions, true))->toBeFalse();

    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);
});

// --- CSS contract ----------------------------------------------------------

it('defines the brand logo sizes as tokens in one place and uses them in the panel theme', function (): void {
    $tokens = (string) file_get_contents(resource_path('css/theme.css'));
    $panel = (string) file_get_contents(resource_path('css/filament/theme.css'));
    $defaults = (string) file_get_contents(app_path('Support/Filament/PanelDefaults.php'));

    foreach ([
        '--brand-logo-compact-height',
        '--brand-logo-simple-max-width',
        '--brand-logo-simple-max-height',
        '--brand-mark-simple-size',
        '--brand-logo-sidebar-max-height',
        '--brand-logo-sidebar-padding-inline',
    ] as $token) {
        expect((bool) preg_match('/^\s*'.preg_quote($token, '/').':/m', $tokens))->toBeTrue("{$token} is not defined in theme.css")
            ->and(str_contains($panel, 'var('.$token.')'))->toBeTrue("{$token} is not used by the panel theme")
            ->and((bool) preg_match('/^\s*'.preg_quote($token, '/').':/m', $panel))->toBeFalse("{$token} is redefined in the panel theme");
    }

    expect(str_contains($defaults, "->brandLogoHeight('var(--pl-brand-logo-height)')"))->toBeTrue()
        // The full-height sidebar only from lg, and only for the sidebar layout.
        ->and(str_contains($panel, 'margin-inline-start: var(--sidebar-width);'))->toBeTrue()
        ->and(str_contains($panel, 'margin-top: calc(-1 * var(--topbar-height));'))->toBeTrue();
});
