<?php

declare(strict_types=1);

use App\Modules\Branding\Enums\LogoVariant;
use Illuminate\Support\Str;
use Tests\Support\BrandingTestHelpers as Images;

use function Pest\Laravel\get;

/*
 * ADR-0053: the logo is served same-origin on the admin, app and pay hosts,
 * with its real type, `nosniff`, a year-long immutable cache (the URL is
 * versioned) and no cookies.
 */

it('serves the logo on every host that shows it, with safe cache headers', function (string $host): void {
    $bytes = Images::png();
    $logo = Images::storeLogo(LogoVariant::Light, $bytes);
    $url = 'http://'.config()->string("axispay.surfaces.{$host}").Images::url($logo);

    $response = get($url)->assertOk();

    expect($response->getContent())->toBe($bytes)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and(str_contains((string) $response->headers->get('Content-Security-Policy'), "default-src 'none'"))->toBeTrue();

    $cache = (string) $response->headers->get('Cache-Control');
    expect(str_contains($cache, 'public'))->toBeTrue()
        ->and(str_contains($cache, 'max-age=31536000'))->toBeTrue()
        ->and(str_contains($cache, 'immutable'))->toBeTrue()
        ->and($response->headers->getCookies())->toBe([])
        ->and(str_contains((string) $response->headers->get('Vary'), 'Cookie'))->toBeFalse();
})->with(['admin', 'app', 'pay']);

it('serves the dark variant under its own URL', function (): void {
    Images::storeLogo(LogoVariant::Light);
    $dark = Images::storeLogo(LogoVariant::Dark, Images::png(20, 10));

    get(adminUrl(Images::url($dark)))->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('answers 404 for an old version, an unknown variant or a missing logo', function (): void {
    $logo = Images::storeLogo();
    $stale = strtolower((string) Str::ulid());

    get(adminUrl("/branding/platform-logo/light/{$stale}.png"))->assertNotFound();
    get(adminUrl("/branding/platform-logo/dark/{$logo->version}.png"))->assertNotFound();
    get(adminUrl("/branding/platform-logo/other/{$logo->version}.png"))->assertNotFound();
    get(payUrl("/branding/platform-logo/light/{$logo->version}.svg"))->assertNotFound();
});

it('is not served on the API host', function (): void {
    $logo = Images::storeLogo();

    get(apiUrl(Images::url($logo)))->assertNotFound();
});
