<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Checkout\Actions\UnblockCheckout;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;
use Tests\Support\BrandingTestHelpers as Images;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\withSession;

/**
 * Tenant isolation of the checkout (plan 6.6, rules.md rule 3): a tenant
 * user never reaches another tenant's link detail nor its unblock action
 * (404); a payer's session can only continue an attempt of the link it is
 * on; and every pay-host route is reviewed below (a new one fails the
 * coverage test until it is added).
 */

/**
 * Pay-host routes, each reviewed for isolation: the link comes from its
 * public token through the tenant-safe resolver (the same 404 for any
 * invalid or foreign token), never from an ID in the URL.
 *
 * @var array<string, string>
 */
const REVIEWED_PAY_ROUTES = [
    'checkout.show' => 'Public token through CheckoutLinkResolver; the tenant context comes from that link.',
    'checkout.complete' => 'Public token through CheckoutLinkResolver.',
    'checkout.status' => 'Public token through CheckoutLinkResolver; reads nothing else.',
    'checkout.attempts.store' => 'Public token through CheckoutLinkResolver; the attempt is the link\'s own (claim under the link lock).',
    'checkout.attempts.continue' => 'Public token; the attempt comes from this session for this link and must belong to it (tested below).',
    'checkout.sandbox.next-action' => 'Sandbox only (local/testing); public token through CheckoutLinkResolver.',
    'checkout.legal' => 'Public token through CheckoutLinkResolver; the document is read through the tenant scope of that link (cross-tenant case in Checkout/CheckoutLegalTest).',
    'checkout.merchant-logo' => 'Public token through CheckoutLinkResolver; the logo is read in that link\'s tenant (and by its tenant_id), so another merchant\'s version answers 404 (tested below).',
    'checkout.platform-legal' => 'Platform documents only (no tenant data, no parameters).',
    'checkout.fallback' => 'The uniform 404 page.',
    'pay.branding.platform-logo' => 'Platform asset (ADR-0053): no tenant data and no tenant parameter, read from the platform table by variant and version only; cookie-less, the same bytes for every payer, and a merchant\'s logo version answers 404 (tested below).',
    'pay.branding.favicon' => 'Platform asset (ADR-0053): no tenant data and no tenant parameter, read from the platform table by size and version only; cookie-less, the same bytes for every payer (tested below).',
];

it('answers 404 for another tenant\'s link detail and never runs its unblock action', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['checkout_blocked_until' => now()->addDay(), 'checkout_block_reason' => 'card_testing']));
    $other = ApiTestHelpers::readyTenant();
    $intruder = actingAsTenantUser(tenantUser($other, [SystemRole::Owner]));
    GatewayTestHelpers::reauthenticated();

    get(appUrl('/payment-links/'.$link->id))->assertNotFound();

    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])->assertNotFound();

    // The action itself, reached directly: the foreign link is invisible in the intruder's tenant.
    expect(fn () => Checkout::inTenant(ApiTestHelpers::link($other), static fn () => app(UnblockCheckout::class)->handleForUser($intruder, PaymentLink::query()->findOrFail($link->id))))
        ->toThrow(ModelNotFoundException::class);

    expect(Checkout::freshLink($link)->isCheckoutBlocked())->toBeTrue();
});

it('refuses to continue an attempt of another link or tenant from the session, without calling the gateway', function (string $whose): void {
    [$tenant, $link, $fake] = Checkout::scenario();
    $foreignLink = $whose === 'other link' ? ApiTestHelpers::link($tenant) : Checkout::scenario()[1];
    $foreign = Checkout::inTenant($foreignLink, static fn (): PaymentAttempt => PaymentAttempt::factory()->createOne([
        'payment_link_id' => $foreignLink->id,
        'gateway_connection_id' => Checkout::connectionOf($foreignLink)->id,
    ]));
    $calls = count($fake->calls);

    withSession(["checkout.{$link->id}.next_action" => $foreign->id]);

    Checkout::continue($link)->assertJson(['outcome' => 'error']);

    expect($fake->calls)->toHaveCount($calls);
})->with(['other link', 'other tenant']);

it('shows a link\'s legal document only from that link\'s merchant, and 404 for a document only another tenant has (ADR-0056)', function (): void {
    [$a] = Checkout::scenario();
    [$b, $linkB] = Checkout::scenario();
    Checkout::legalDocument($a, LegalDocumentKind::Terms, body: 'Solo del comercio A.');
    Checkout::removeLegalDocument($b, LegalDocumentKind::Terms);

    get(payUrl('/l/'.$linkB->public_token.'/legal/terms'))->assertNotFound()->assertDontSee('Solo del comercio A.');
    get(payUrl('/l/'.$linkB->public_token))->assertOk()->assertDontSee('Solo del comercio A.');
});

it('serves under a link only its own merchant\'s logo, and shows only that logo (ADR-0056)', function (): void {
    [$a, $linkA] = Checkout::scenario();
    [$b, $linkB] = Checkout::scenario();
    $logoA = Images::storeTenantLogo($a);
    $darkA = Images::storeTenantLogo($a, LogoVariant::Dark);
    $logoB = Images::storeTenantLogo($b);

    // Tenant A's versions under tenant B's link (and the other way round): 404.
    get(payUrl('/l/'.$linkB->public_token.'/logo/light/'.$logoA->version.'.png'))->assertNotFound();
    get(payUrl('/l/'.$linkB->public_token.'/logo/dark/'.$darkA->version.'.png'))->assertNotFound();
    get(payUrl('/l/'.$linkA->public_token.'/logo/light/'.$logoB->version.'.png'))->assertNotFound();

    // Each link serves and shows its own merchant's logo only.
    get(payUrl('/l/'.$linkB->public_token.'/logo/light/'.$logoB->version.'.png'))->assertOk();
    get(payUrl('/l/'.$linkB->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()
        ->assertSee($logoB->version)
        ->assertDontSee($logoA->version)
        ->assertDontSee($darkA->version);
});

it('serves the platform logo and favicon the same way under any link, and never a merchant\'s logo through them (ADR-0053)', function (): void {
    [$a, $linkA] = Checkout::scenario();
    $merchantLogo = Images::storeTenantLogo($a);
    $logoBytes = Images::png(30, 10);
    $platformLogo = Images::storeLogo(LogoVariant::Light, $logoBytes);
    $favicon = Images::storeFavicon()[32];

    // A merchant's logo version is unknown to the platform route: 404.
    get(payUrl('/branding/platform-logo/light/'.$merchantLogo->version.'.png'))->assertNotFound();

    // Visiting a link first (a payer session on the pay host) changes nothing.
    get(payUrl('/l/'.$linkA->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk();

    foreach ([Images::url($platformLogo) => $logoBytes, Images::faviconUrl($favicon) => $favicon->content] as $path => $bytes) {
        $response = get(payUrl($path))->assertOk();

        expect($response->getContent())->toBe($bytes)
            ->and($response->headers->getCookies())->toBe([]);
    }
});

it('reviews every pay-host route for isolation', function (): void {
    $payHost = config()->string('axispay.surfaces.pay');
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getDomain() === $payHost) {
            $routes[] = (string) $route->getName();
        }
    }

    $unreviewed = array_values(array_diff($routes, array_keys(REVIEWED_PAY_ROUTES)));

    expect($routes)->not->toBeEmpty()
        ->and($unreviewed)->toBe([], 'Pay-host routes without an isolation review: '.implode(', ', $unreviewed));
});
