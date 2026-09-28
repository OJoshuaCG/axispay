<?php

declare(strict_types=1);

use App\Modules\Checkout\Providers\CheckoutServiceProvider;
use App\Modules\Checkout\Services\CheckoutFonts;
use App\Modules\Checkout\Services\CheckoutRateLimiter;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travel;

/**
 * ADR-0051, Phase 4 iteration 5 (card testing and abuse): atomic counting of
 * confirmations that reach the gateway, unrecognized tokens and gateway
 * failures counted per client, separate request limits per group of the
 * pay host, Turnstile keys required, fresh Turnstile tokens after a spent
 * one, and the card fingerprint kept for forensics.
 */
beforeEach(function (): void {
    config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
});

function abuseLinkCount(PaymentLink $link): int
{
    $attempts = RateLimiter::attempts('checkout:link:'.$link->id);

    return is_numeric($attempts) ? (int) $attempts : 0;
}

// M1 -----------------------------------------------------------------------

it('never counts requests answered in progress, so they cannot pause the link (M1)', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds')->assertJson(['outcome' => 'requires_action']);

    foreach (range(1, 6) as $i) {
        Checkout::pay($link, 'ctoken_success_'.$i)->assertJson(['outcome' => 'in_progress']);
    }

    expect(abuseLinkCount($link))->toBe(1)
        ->and(app(CheckoutRateLimiter::class)->pausedMinutes(Checkout::freshLink($link)))->toBeNull();
});

it('reserves confirmations atomically and takes back the one over the limit (M1)', function (): void {
    config(['axispay.checkout.rate_limits.ip_attempts' => 2]);
    $tenant = ApiTestHelpers::readyTenant();
    $links = array_map(static fn (): PaymentLink => ApiTestHelpers::link($tenant, false), range(1, 3));
    $guard = app(CheckoutRateLimiter::class);

    expect($guard->reserveConfirmation($links[0], '203.0.113.7'))->toBeNull()
        ->and($guard->reserveConfirmation($links[1], '203.0.113.7'))->toBeNull()
        ->and($guard->reserveConfirmation($links[2], '203.0.113.7'))->toBeInt()
        // The refused one was taken back, and the link was not paused (the IP was over).
        ->and(abuseLinkCount($links[2]))->toBe(0)
        ->and($guard->pausedMinutes($links[2]))->toBeNull()
        ->and($guard->reserveConfirmation($links[2], '198.51.100.1'))->toBeNull();
});

it('pauses the link on the confirmation over its limit, and only then (M1)', function (): void {
    [, $link] = Checkout::scenario();
    $guard = app(CheckoutRateLimiter::class);

    foreach (range(1, 5) as $i) {
        expect($guard->reserveConfirmation($link, '203.0.113.'.$i))->toBeNull();
    }

    expect($guard->pausedMinutes($link))->toBeNull()
        ->and($guard->reserveConfirmation($link, '203.0.113.9'))->toBe(30)
        ->and($guard->pausedMinutes($link))->toBe(30);
});

// M2 -----------------------------------------------------------------------

it('limits unrecognized tokens per client network across links (M2)', function (): void {
    config(['axispay.checkout.rate_limits.ip_bogus_attempts' => 4]);
    [$tenant, $first, $fake] = Checkout::scenario();
    $links = [$first, ...array_map(static fn (): PaymentLink => ApiTestHelpers::link($tenant, false), range(1, 2))];

    foreach ([0, 0, 1, 1] as $index) {
        $fake->failNext('inspectPaymentMethod', new GatewayRequestException('No such token.', 'resource_missing', null, 404));
        Checkout::pay($links[$index], 'ctoken_bogus')->assertJson(['outcome' => 'error']);
    }

    $calls = count($fake->callsTo('inspectPaymentMethod'));

    Checkout::pay($links[2], 'ctoken_success')->assertJson(['outcome' => 'rate_limited']);

    expect($fake->callsTo('inspectPaymentMethod'))->toHaveCount($calls);
});

it('counts gateway failures while reading the token per link and client (M2)', function (): void {
    [, $link, $fake] = Checkout::scenario();

    foreach (range(1, 5) as $i) {
        $fake->failNext('inspectPaymentMethod', new GatewayUnavailableException('Rate limited.', 'rate_limit', null, 429));
        Checkout::pay($link, 'ctoken_success_'.$i)->assertJson(['outcome' => 'error']);
    }

    Checkout::pay($link, 'ctoken_success')->assertJson(['outcome' => 'rate_limited']);

    // Only this client: the link itself is not paused.
    expect(app(CheckoutRateLimiter::class)->pausedMinutes(Checkout::freshLink($link)))->toBeNull();
});

// M3 -----------------------------------------------------------------------

it('gives status polling its own request limit, answered with a 429 the page can show (M3)', function (): void {
    [, $link] = Checkout::scenario();

    foreach (range(1, 90) as $i) {
        getJson(payUrl('/l/'.$link->public_token.'/status'))->assertOk();
    }

    getJson(payUrl('/l/'.$link->public_token.'/status'))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJson(['outcome' => 'too_many_requests'])
        ->assertJsonStructure(['message', 'retry_after_seconds']);

    // Polling never eats into the budget of paying.
    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);
});

it('answers a short 429 page when the payment page is reloaded too often (M3)', function (): void {
    [, $link] = Checkout::scenario();

    foreach (range(1, 60) as $i) {
        get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0 (Test)'])->assertOk();
    }

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0 (Test)'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee(__('checkout.states.too_many_requests.heading'));

    getJson(payUrl('/l/'.$link->public_token.'/status'))->assertOk();
});

// M4 -----------------------------------------------------------------------

it('refuses to boot in production without both Turnstile keys, and only there (M4)', function (?string $site, ?string $secret): void {
    config(['services.turnstile.site_key' => $site, 'services.turnstile.secret_key' => $secret]);

    expect(fn () => CheckoutServiceProvider::assertTurnstileKeys('production'))->toThrow(RuntimeException::class, 'must be set in production');

    CheckoutServiceProvider::assertTurnstileKeys('local');
    CheckoutServiceProvider::assertTurnstileKeys('testing');
})->with([
    'no site key' => [null, 'secret'],
    'no secret key' => ['site', ''],
    'neither' => [null, null],
]);

it('reports missing Turnstile keys in the doctor, as an error in production (M4)', function (): void {
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    Artisan::call('axispay:doctor');
    expect(Artisan::output())->toContain('Turnstile')->toContain('TURNSTILE_SITE_KEY, TURNSTILE_SECRET_KEY unset');

    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(Artisan::call('axispay:doctor'))->toBe(1);
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('logs a payment page that requires Turnstile without a site key (M4)', function (): void {
    config(['services.turnstile.site_key' => null, 'axispay.checkout.turnstile_after_failures' => 1]);
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    $log = captureDefaultLog();

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0 (Test)'])->assertOk()->assertSee(__('checkout.messages.security_unavailable'));

    expect(collect($log->getRecords())->contains(static fn ($record): bool => $record->level->getName() === 'ERROR' && str_contains($record->message, 'TURNSTILE_SITE_KEY')))->toBeTrue();
});

// L1 -----------------------------------------------------------------------

it('asks for a fresh Turnstile token after a verified one was spent, whatever happens next (L1)', function (): void {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'pay.localhost', 'action' => 'checkout'])]);
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline')->assertJson(['turnstile_required' => true]);
    $fake->failNext('inspectPaymentMethod', new GatewayUnavailableException('Unavailable.', null, null, 503));

    Checkout::pay($link, 'ctoken_success', ['turnstile_token' => 'XXXX.DUMMY.TOKEN.XXXX'])
        ->assertJson(['outcome' => 'error', 'turnstile_required' => true]);
});

it('logs an expired or reused Turnstile token distinctly and asks for a new one (L1)', function (): void {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    $log = captureDefaultLog();

    Checkout::pay($link, 'ctoken_success', ['turnstile_token' => 'XXXX.DUMMY.TOKEN.XXXX'])
        ->assertJson(['outcome' => 'turnstile_required', 'turnstile_required' => true]);

    expect(collect($log->getRecords())->contains(static fn ($record): bool => $record->message === 'A Turnstile token was expired or already used.'))->toBeTrue();
});

// L3 -----------------------------------------------------------------------

it('keeps the card fingerprint on the attempt and its declines, for forensics (L3)', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');

    [$attempt] = Checkout::attempts($link);
    $failure = Checkout::inTenant($link, static fn () => PaymentAttemptFailure::query()->where('payment_attempt_id', $attempt->id)->sole());

    expect($attempt->card_fingerprint)->toBe('fp_fake_0002')
        ->and($failure->card_fingerprint)->toBe('fp_fake_0002');

    // Never on the payer's page.
    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0 (Test)'])->assertDontSee('fp_fake_0002');
});

// P15 ----------------------------------------------------------------------

it('answers the status poll without starting a session (P15)', function (): void {
    [, $link] = Checkout::scenario();

    $response = getJson(payUrl('/l/'.$link->public_token.'/status'))->assertOk()->assertJson(['state' => 'active']);

    expect(collect($response->headers->getCookies())->map->getName()->all())->toBe([]);
});

// P14, P16, P18 -------------------------------------------------------------

it('defers Stripe.js before the page script and preconnects to Stripe\'s API (P14)', function (): void {
    [, $link] = Checkout::scenario();

    $html = (string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();
    $stripe = strpos($html, 'js.stripe.com/dahlia/stripe.js');
    $entry = strpos($html, 'resources/js/checkout/checkout.js') ?: strpos($html, '/build/assets/checkout-');

    expect(preg_match('#<script src="https://js\.stripe\.com/dahlia/stripe\.js" defer#', $html))->toBe(1);
    expect(str_contains($html, '<link rel="preconnect" href="https://api.stripe.com" crossorigin>'))->toBeTrue();
    expect(str_contains($html, 'rel="preconnect" href="https://js.stripe.com"'))->toBeFalse();
    expect(is_int($stripe) && is_int($entry) && $stripe < $entry)->toBeTrue();
});

it('reads the tenant once and the connection once for a page load (P16)', function (): void {
    [, $link] = Checkout::scenario();

    DB::enableQueryLog();
    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from `tenants`')))->toHaveCount(1)
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from `gateway_connections`')))->toHaveCount(1);
});

it('re-reads a payment left in 3D Secure less often the longer it stays idle (P18)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds')->assertJson(['outcome' => 'requires_action']);
    $reads = static fn (): int => count($fake->callsTo('retrievePayment'));
    $poll = static fn () => getJson(payUrl('/l/'.$link->public_token.'/status'))->assertOk();
    $start = $reads();

    travel(6)->seconds();
    $poll();
    expect($reads())->toBe($start + 1); // idle 6 s: every 5 s

    travel(70)->seconds();
    $poll();
    $afterMinute = $reads();
    travel(6)->seconds();
    $poll();
    expect($reads())->toBe($afterMinute); // idle over a minute: every 10 s

    travel(5)->seconds();
    $poll();
    expect($reads())->toBe($afterMinute + 1);

    travel(60)->seconds();
    $poll();
    $afterTwo = $reads();
    travel(11)->seconds();
    $poll();
    expect($reads())->toBe($afterTwo); // idle over two minutes: every 20 s

    travel(10)->seconds();
    $poll();
    expect($reads())->toBe($afterTwo + 1);
});

it('sends Stripe only the Mukta weights the card form uses (P19)', function (): void {
    expect(CheckoutFonts::STRIPE_WEIGHTS)->toBe([400, 500]);
});
