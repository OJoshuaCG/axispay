<?php

declare(strict_types=1);

use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Shared\Support\Brand;
use Tests\Support\BrandingTestHelpers as Images;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * ADR-0053: where and how the platform brand shows. Both panels (sign-in
 * pages and signed-in pages) and the checkout's "Powered by" line follow the
 * superadmin's display mode; without a logo the name is always shown; the
 * name always stays in page titles and is the logo's alt text.
 */

function brandVisibleName(): string
{
    return 'aria-hidden="true">'.e(Brand::displayName()).'</span>';
}

function brandLogoImg(string $url): string
{
    return 'src="'.$url.'" alt="'.e(Brand::displayName()).'"';
}

function checkoutHtml(): string
{
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'en']));

    return (string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();
}

// --- Panels ----------------------------------------------------------------

it('shows the name and the stand-in mark in the panels when there is no logo', function (string $url, BrandDisplayMode $mode): void {
    Images::setMode($mode);

    $html = (string) get($url)->assertOk()->getContent();

    expect(str_contains($html, '/branding/platform-logo/'))->toBeFalse()
        ->and(str_contains($html, 'data-brand-mode="name_only"'))->toBeTrue()
        ->and(str_contains($html, e(Brand::displayName()).'</span>'))->toBeTrue()
        ->and(str_contains($html, 'fill-brand-mark'))->toBeTrue();
})->with([
    'admin sign-in' => fn (): string => adminUrl('/login'),
    'app sign-in' => fn (): string => appUrl('/login'),
])->with(BrandDisplayMode::cases());

it('shows the logo and the name in the panels by default', function (string $url): void {
    $logo = Images::storeLogo();

    $html = (string) get($url)->assertOk()->getContent();

    expect(str_contains($html, brandLogoImg(Images::url($logo))))->toBeTrue()
        ->and(str_contains($html, brandVisibleName()))->toBeTrue()
        ->and(str_contains($html, 'data-brand-mode="logo_and_name"'))->toBeTrue();
})->with([
    'admin sign-in' => fn (): string => adminUrl('/login'),
    'app sign-in' => fn (): string => appUrl('/login'),
]);

it('shows only the logo in "logo only", keeping the name as alt text and in the title', function (string $url): void {
    $logo = Images::storeLogo();
    Images::setMode(BrandDisplayMode::LogoOnly);

    $html = (string) get($url)->assertOk()->getContent();

    expect(str_contains($html, brandLogoImg(Images::url($logo))))->toBeTrue()
        ->and(str_contains($html, brandVisibleName()))->toBeFalse()
        ->and(str_contains($html, 'data-brand-mode="logo_only"'))->toBeTrue()
        ->and((bool) preg_match('/<title>[^<]*'.preg_quote(e(Brand::displayName()), '/').'[^<]*<\/title>/', $html))->toBeTrue();
})->with([
    'admin sign-in' => fn (): string => adminUrl('/login'),
    'app sign-in' => fn (): string => appUrl('/login'),
]);

it('shows only the name in "name only" even with a logo', function (): void {
    Images::storeLogo();
    Images::setMode(BrandDisplayMode::NameOnly);

    $html = (string) get(adminUrl('/login'))->assertOk()->getContent();

    expect(str_contains($html, '/branding/platform-logo/'))->toBeFalse()
        ->and(str_contains($html, 'data-brand-mode="name_only"'))->toBeTrue();
});

it('renders the dark variant for dark mode only when one exists', function (): void {
    $light = Images::storeLogo(LogoVariant::Light);

    $html = (string) get(adminUrl('/login'))->assertOk()->getContent();
    expect(str_contains($html, 'fi-logo-dark'))->toBeFalse()
        ->and(str_contains($html, Images::url($light)))->toBeTrue();

    $dark = Images::storeLogo(LogoVariant::Dark);

    $html = (string) get(adminUrl('/login'))->assertOk()->getContent();
    expect(str_contains($html, 'fi-logo-dark'))->toBeTrue()
        ->and(str_contains($html, brandLogoImg(Images::url($dark))))->toBeTrue()
        ->and(str_contains($html, brandLogoImg(Images::url($light))))->toBeTrue();
});

it('shows the brand in the signed-in panels too', function (): void {
    $logo = Images::storeLogo();

    actingAs(platformAdmin(), 'platform');
    expect(str_contains((string) get(adminUrl('/'))->assertOk()->getContent(), brandLogoImg(Images::url($logo))))->toBeTrue();

    actingAsTenantUser(tenantUser());
    expect(str_contains((string) get(appUrl('/'))->assertOk()->getContent(), brandLogoImg(Images::url($logo))))->toBeTrue();
});

// --- Checkout footer -------------------------------------------------------

it('shows only the name in the checkout footer when there is no logo', function (BrandDisplayMode $mode): void {
    Images::setMode($mode);

    $html = checkoutHtml();

    expect(str_contains($html, '/branding/platform-logo/'))->toBeFalse()
        ->and(str_contains($html, 'Powered by <span class="inline-flex items-center gap-1 align-middle" data-brand-mode="name_only"><span>'.e(Brand::displayName()).'</span>'))->toBeTrue();
})->with(BrandDisplayMode::cases());

it('shows the logo in the checkout footer per display mode', function (BrandDisplayMode $mode, bool $logoShown, bool $nameShown): void {
    $logo = Images::storeLogo();
    Images::setMode($mode);

    $html = checkoutHtml();

    expect(str_contains($html, brandLogoImg(Images::url($logo))))->toBe($logoShown)
        ->and(str_contains($html, brandVisibleName()))->toBe($nameShown && $logoShown)
        ->and(str_contains($html, 'data-brand-mode="'.$mode->value.'"'))->toBeTrue()
        ->and(str_contains($html, 'Powered by'))->toBeTrue();
})->with([
    'logo and name' => [BrandDisplayMode::LogoAndName, true, true],
    'logo only' => [BrandDisplayMode::LogoOnly, true, false],
    'name only' => [BrandDisplayMode::NameOnly, false, true],
]);

it('swaps to the dark variant in the checkout footer only when it exists', function (): void {
    $light = Images::storeLogo(LogoVariant::Light);
    expect(str_contains(checkoutHtml(), 'dark:inline-block'))->toBeFalse();

    $dark = Images::storeLogo(LogoVariant::Dark);
    $html = checkoutHtml();

    expect(str_contains($html, brandLogoImg(Images::url($dark))))->toBeTrue()
        ->and(str_contains($html, brandLogoImg(Images::url($light))))->toBeTrue()
        ->and(str_contains($html, 'dark:inline-block'))->toBeTrue();
});

it('keeps the checkout logo within the page\'s own-origin image policy', function (): void {
    Images::storeLogo();
    [, $link] = Checkout::scenario();

    $response = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk();

    expect(str_contains((string) $response->headers->get('Content-Security-Policy'), "img-src 'self'"))->toBeTrue()
        ->and(str_contains((string) $response->getContent(), 'src="/branding/platform-logo/light/'))->toBeTrue();
});

it('escapes the translated sentence around the brand in Spanish too', function (): void {
    Images::storeLogo();
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'es']));

    $html = (string) get(payUrl('/l/'.$link->public_token.'?lang=es'), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();

    expect(str_contains($html, 'Con la tecnología de <span class="inline-flex items-center gap-1 align-middle"'))->toBeTrue()
        ->and(str_contains($html, "\u{E000}"))->toBeFalse();
});
