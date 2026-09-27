<?php

declare(strict_types=1);

use App\Modules\Checkout\Actions\RecordCheckoutOpening;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use Illuminate\Support\Carbon;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;

/**
 * The payment page (plan 11.1-11.3, 11.6, 11.8; DESIGN.md; ADR-0051).
 */
/**
 * @return array<mixed>
 */
function checkoutConfig(string $html): array
{
    preg_match('#<script type="application/json" id="checkout-config"[^>]*>(.*?)</script>#s', $html, $match);

    return jsonArray($match[1] ?? '');
}

it('renders an active link with the merchant, the amount and the pay button', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['amount_minor' => 150_000, 'currency' => 'MXN', 'description' => 'Pedido #A-1029', 'metadata' => ['secret_ref' => 'internal-99'], 'client_reference_id' => 'REF-SECRET']));

    $response = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0']);

    $response->assertOk()
        ->assertSee('Tienda Demo')
        ->assertSee('Pedido #A-1029')
        ->assertSee('1,500.00', false)
        ->assertSee('data-pay-button', false)
        // Plan 11.3: never metadata nor the client reference.
        ->assertDontSee('internal-99')
        ->assertDontSee('REF-SECRET')
        ->assertSee('Con la tecnología de', false);
});

it('sends the checkout security headers with a nonce-based CSP', function (): void {
    [, $link] = Checkout::scenario();

    $response = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0']);
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'")
        ->toContain('https://js.stripe.com')
        ->toContain('https://*.js.stripe.com')
        ->toContain('https://hooks.stripe.com')
        ->toContain('https://challenges.cloudflare.com')
        ->toContain('connect-src \'self\' https://api.stripe.com')
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");

    expect($csp)->not->toContain('unsafe-inline')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $nonce);
    $value = $nonce[1] ?? 'missing';
    // Every script tag of the page carries the nonce.
    preg_match_all('/<script\b[^>]*>/', (string) $response->getContent(), $scripts);
    expect($scripts[0])->not->toBeEmpty();

    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$value.'"');
    }

    $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('initializes Stripe.js with the platform key and the connected account (Connect methods)', function (): void {
    [, $link] = Checkout::scenario();
    $connection = Checkout::connectionOf($link);

    $config = checkoutConfig((string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->getContent());

    expect($config['publishableKey'])->toBe('pk_test_fake_platform')
        ->and($config['stripeAccount'])->toBe($connection->provider_account_id)
        ->and($config['amount'])->toBe(150_000)
        ->and($config['currency'])->toBe('usd');
});

it('initializes Stripe.js with the merchant publishable key and no account for api_key (case 20)', function (): void {
    [, $link] = Checkout::scenario(connection: static fn ($f) => $f->apiKey('rk_test_merchantsecret0000A1B2', 'pk_test_merchant_publishable'));

    $html = (string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->getContent();
    $config = checkoutConfig($html);

    expect($config['publishableKey'])->toBe('pk_test_merchant_publishable')
        ->and($config['stripeAccount'])->toBeNull()
        ->and($html)->not->toContain('rk_test_')
        ->and($html)->not->toContain('sk_test_');
});

it('uses the link language unless the payer chose one', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'en']));

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'es-MX'])->assertSee('Powered by')->assertSee('lang="en"', false);
    get(payUrl('/l/'.$link->public_token.'?lang=es'), ['User-Agent' => 'Mozilla/5.0'])->assertSee('Con la tecnología de')->assertSee('lang="es"', false);
});

it('shows the informative page of paid, expired and canceled links with HTTP 200', function (PaymentLinkStatus $status, string $text): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->inStatus($status));

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])
        ->assertOk()
        ->assertSee($text)
        ->assertDontSee('data-pay-button', false)
        ->assertDontSee('js.stripe.com/dahlia', false);
})->with([
    [PaymentLinkStatus::Paid, 'Este cobro ya fue pagado'],
    [PaymentLinkStatus::Expired, 'Este enlace de pago expiró'],
    [PaymentLinkStatus::Canceled, 'Este enlace de pago ya no está disponible'],
]);

it('expires an active link past its expiry when the page opens (lazy check)', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->pastExpiry());

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertSee('Este enlace de pago expiró');

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Expired);
});

it('answers the same 404 page for every invalid token', function (): void {
    [, $link] = Checkout::scenario();
    $bodies = [];

    foreach ([substr($link->public_token, 0, -1).'x', 'short', str_repeat('A', 43), strtolower($link->public_token), '%3Cscript%3E'] as $token) {
        $response = get(payUrl('/l/'.$token), ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en']);
        $response->assertNotFound()->assertSee("We couldn't find this link")->assertDontSee('Tienda Demo');
        $bodies[] = preg_replace('/nonce="[^"]+"|nonce-[A-Za-z0-9]+|name="_token" value="[^"]+"|content="[A-Za-z0-9]{40}"|name="redirect" value="[^"]*"|<link rel="preload"[^>]*>/', '', (string) $response->getContent());
        expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }

    // (Vite preloads each asset once per process, so preload tags are left out of the comparison.)
    expect(array_unique(array_map(static fn (mixed $body): string => preg_replace('/\s+/', '', (string) $body) ?? '', $bodies)))->toHaveCount(1);
});

it('counts openings, records payment_link.opened at most every 30 minutes and ignores link previewers', function (): void {
    Carbon::setTestNow('2026-09-27 12:00:00');
    [, $link] = Checkout::scenario();
    $url = payUrl('/l/'.$link->public_token);

    get($url, ['User-Agent' => 'WhatsApp/2.23.20 A']);
    expect(Checkout::freshLink($link)->open_count)->toBe(0);

    get($url, ['User-Agent' => 'Mozilla/5.0']);
    get($url, ['User-Agent' => 'Mozilla/5.0']);
    travel(31)->minutes();
    get($url, ['User-Agent' => 'Mozilla/5.0']);

    $fresh = Checkout::freshLink($link);
    $events = Checkout::inTenant($link, static fn () => DomainEvent::query()->where('type', DomainEventType::PaymentLinkOpened->value)->orderBy('id')->get());

    expect($fresh->open_count)->toBe(3)
        ->and($fresh->first_opened_at?->toDateTimeString())->toBe('2026-09-27 12:00:00')
        ->and($events)->toHaveCount(2)
        ->and($events->get(0)?->data)->toMatchArray(['first_open' => true, 'open_count' => 1])
        ->and($events->get(1)?->data)->toMatchArray(['first_open' => false, 'open_count' => 3]);
});

it('treats an empty user agent as a previewer', function (): void {
    expect(RecordCheckoutOpening::isPreviewer(null))->toBeTrue()
        ->and(RecordCheckoutOpening::isPreviewer('Slackbot-LinkExpanding 1.0'))->toBeTrue()
        ->and(RecordCheckoutOpening::isPreviewer('Mozilla/5.0 (iPhone)'))->toBeFalse();
});
