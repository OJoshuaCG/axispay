<?php

declare(strict_types=1);

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\TenantLogo;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Illuminate\Support\Str;
use Tests\Support\BrandingTestHelpers as Images;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;

/*
 * The merchant's logo on the payment pages (ADR-0056 part B): centered at the
 * top with the merchant's name as its alt, the dark variant swapped in dark
 * theme (or the light logo on a light plate), the name as text without a
 * logo; served only on the pay host, under the link's own address, versioned
 * and cached for a year, without session or cookies, and only the link's
 * own merchant's logo.
 */

const MERCHANT_LOGO_BROWSER = ['User-Agent' => 'Mozilla/5.0'];

function merchantLogoPath(PaymentLink $link, TenantLogo $logo): string
{
    return '/l/'.$link->public_token.'/logo/'.$logo->variant->value.'/'.$logo->version.'.png';
}

// --- The header ------------------------------------------------------------------

it('shows the logo centered with the merchant name as alt, and the dark variant in dark theme', function (): void {
    [$tenant, $link] = Checkout::scenario();
    $light = Images::storeTenantLogo($tenant, width: 400, height: 120);
    $dark = Images::storeTenantLogo($tenant, LogoVariant::Dark, width: 300, height: 100);

    get(payUrl('/l/'.$link->public_token), MERCHANT_LOGO_BROWSER)->assertOk()
        ->assertSee('data-merchant-logo', false)
        ->assertSee(merchantLogoPath($link, $light), false)
        ->assertSee(merchantLogoPath($link, $dark), false)
        ->assertSee('alt="Tienda Demo" width="400" height="120"', false)
        ->assertSee('alt="Tienda Demo" width="300" height="100"', false)
        ->assertSee('dark:hidden', false)
        ->assertSee('hidden dark:block', false)
        ->assertDontSee('bg-logo-plate', false)
        // The name is the logo's alt, not a second visible heading.
        ->assertDontSee('text-center text-2xl font-semibold break-words text-fg">Tienda Demo', false)
        // The language and theme controls stay, on the row above.
        ->assertSee('role="group"', false)
        ->assertSee('data-theme-toggle', false);
});

it('shows the light logo on a light plate when there is no dark variant', function (): void {
    [$tenant, $link] = Checkout::scenario();
    $light = Images::storeTenantLogo($tenant);

    get(payUrl('/l/'.$link->public_token), MERCHANT_LOGO_BROWSER)->assertOk()
        ->assertSee(merchantLogoPath($link, $light), false)
        ->assertSee('rounded-lg p-inset-sm bg-logo-plate', false)
        ->assertDontSee('/logo/dark/', false)
        ->assertDontSee('hidden dark:block', false);
});

it('shows the merchant name as text without a logo, and ignores a dark variant alone', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Images::storeTenantLogo($tenant, LogoVariant::Dark);

    get(payUrl('/l/'.$link->public_token), MERCHANT_LOGO_BROWSER)->assertOk()
        ->assertSee('<p class="text-center text-2xl font-semibold break-words text-fg">Tienda Demo</p>', false)
        ->assertDontSee('data-merchant-logo', false)
        ->assertDontSee('/logo/dark/', false);
});

it('shows the logo on every state of the page', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->inStatus(PaymentLinkStatus::Canceled));
    $light = Images::storeTenantLogo($tenant);

    get(payUrl('/l/'.$link->public_token), MERCHANT_LOGO_BROWSER)->assertOk()->assertSee(merchantLogoPath($link, $light), false);
});

it('shows the logo on the merchant\'s legal document page', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::legalDocument($tenant, LegalDocumentKind::Terms, body: 'Términos.');
    $light = Images::storeTenantLogo($tenant);

    get(payUrl('/l/'.$link->public_token.'/legal/terms'), MERCHANT_LOGO_BROWSER)->assertOk()->assertSee(merchantLogoPath($link, $light), false);
});

// --- Serving -------------------------------------------------------------------------

it('serves the logo with its type, nosniff, a year-long immutable cache and no cookies', function (LogoVariant $variant): void {
    [$tenant, $link] = Checkout::scenario();
    $bytes = Images::png(60, 20);

    if ($variant === LogoVariant::Dark) {
        Images::storeTenantLogo($tenant);
    }

    $logo = Images::storeTenantLogo($tenant, $variant, $bytes, 60, 20);

    $response = get(payUrl(merchantLogoPath($link, $logo)))->assertOk();
    $cache = (string) $response->headers->get('Cache-Control');

    expect($response->getContent())->toBe($bytes)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($cache)->toContain('public')
        ->and($cache)->toContain('max-age=31536000')
        ->and($cache)->toContain('immutable')
        ->and($cache)->not->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([])
        ->and(str_contains((string) $response->headers->get('Vary'), 'Cookie'))->toBeFalse();
})->with([LogoVariant::Light, LogoVariant::Dark]);

it('answers the uniform 404, never cached, for an unknown token, an old version, a missing variant or a malformed URL', function (): void {
    [$tenant, $link] = Checkout::scenario();
    $logo = Images::storeTenantLogo($tenant);
    $stale = strtolower((string) Str::ulid());
    $token = $link->public_token;

    foreach ([
        "/l/unknown-token/logo/light/{$logo->version}.png",
        "/l/{$token}/logo/light/{$stale}.png",
        "/l/{$token}/logo/dark/{$logo->version}.png",
        "/l/{$token}/logo/other/{$logo->version}.png",
        "/l/{$token}/logo/light/{$logo->version}.svg",
        "/l/{$token}/logo/light/NOT-A-VERSION.png",
    ] as $path) {
        $response = get(payUrl($path))->assertNotFound();

        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('is not served on the app host', function (): void {
    [$tenant, $link] = Checkout::scenario();
    $logo = Images::storeTenantLogo($tenant);

    get(appUrl(merchantLogoPath($link, $logo)))->assertNotFound();
});
