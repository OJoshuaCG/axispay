<?php

declare(strict_types=1);

use App\Modules\Checkout\Actions\CompleteCheckoutAuthorization;
use App\Modules\Checkout\Http\Middleware\CheckoutSecurityHeaders;
use App\Modules\Checkout\Services\CardTestingGuard;
use App\Modules\Checkout\Services\TurnstileVerifier;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Sandbox\SandboxPaymentGateway;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakePaymentGateway;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\get;

/**
 * Phase 4 payment security (ADR-0051, Ralph iteration 2).
 */
// M1 -----------------------------------------------------------------------

it('never lets bogus confirmation tokens pause a link for other payers', function (): void {
    [, $link, $fake] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);

    foreach (range(1, 5) as $i) {
        \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        Checkout::pay($link, 'ctoken_bogus_'.$i)->assertStatus(503);
    }

    \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    Checkout::pay($link, 'ctoken_bogus_6')->assertStatus(429)->assertJson(['outcome' => 'rate_limited']);

    \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
    Checkout::pay($link, 'ctoken_success')->assertOk()->assertJson(['outcome' => 'paid']);
    expect($fake->callsTo('confirmPayment'))->toHaveCount(1);
});

// M5 -----------------------------------------------------------------------

it('keys the per-IP limit by the /64 network for IPv6 and by the address for IPv4', function (): void {
    expect(CardTestingGuard::clientNetwork('2001:db8:abcd:12:1::1'))->toBe(CardTestingGuard::clientNetwork('2001:db8:abcd:12:ffff:ffff:ffff:ffff'))
        ->and(CardTestingGuard::clientNetwork('2001:db8:abcd:12::1'))->not->toBe(CardTestingGuard::clientNetwork('2001:db8:abcd:13::1'))
        ->and(CardTestingGuard::clientNetwork('203.0.113.9'))->toBe('203.0.113.9')
        ->and(CardTestingGuard::clientNetwork('203.0.113.9'))->not->toBe(CardTestingGuard::clientNetwork('203.0.113.10'));
});

it('limits payers rotating IPv6 addresses inside one /64', function (): void {
    config(['axispay.checkout.turnstile_after_failures' => 99, 'axispay.checkout.rate_limits.link_attempts' => 100]);
    [$tenant, $link] = Checkout::scenario();

    foreach (range(1, 10) as $i) {
        \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:12::'.dechex($i)]);
        Checkout::pay(ApiTestHelpers::link($tenant), 'ctoken_decline_'.$i);
    }

    \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:12::ff']);
    Checkout::pay($link, 'ctoken_success')->assertStatus(429);
});

// M2 -----------------------------------------------------------------------

it('lets only the session that was handed the 3D Secure step continue it', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds')->assertJson(['outcome' => 'requires_action']);
    [$attempt] = Checkout::attempts($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresCapture);

    \Pest\Laravel\flushSession(); // another browser
    Checkout::continue($link)->assertStatus(409)->assertJson(['outcome' => 'in_progress'])->assertJsonMissingPath('payer_message');
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresAction)
        ->and($fake->callsTo('capturePayment'))->toBe([]);
});

it('debounces the continuation per attempt', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds');
    [$attempt] = Checkout::attempts($link);
    $complete = app(CompleteCheckoutAuthorization::class);

    Checkout::inTenant($link, static fn () => $complete->handle(Checkout::freshLink($link), $attempt->id));
    $reads = count($fake->callsTo('retrievePayment'));
    $second = Checkout::inTenant($link, static fn () => $complete->handle(Checkout::freshLink($link), $attempt->id));

    expect($second->outcome->value)->toBe('processing')
        ->and($fake->callsTo('retrievePayment'))->toHaveCount($reads);
});

// M3 -----------------------------------------------------------------------

it('stores payment events reduced and every event encrypted at rest', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_processing');
    [$attempt] = Checkout::attempts($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);
    $connection = Checkout::connectionOf($link);

    $body = (string) json_encode([
        'id' => 'evt_Pii0001', 'type' => 'payment_intent.succeeded', 'account' => $connection->provider_account_id, 'livemode' => false,
        'data' => ['object' => ['id' => $attempt->provider_payment_id, 'object' => 'payment_intent', 'metadata' => ['axispay_attempt_id' => $attempt->id], 'receipt_email' => 'ana@example.com', 'shipping' => ['name' => 'Ana López']]],
    ]);
    \Pest\Laravel\call('POST', apiUrl('/webhooks/stripe/connect/test'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => FakePaymentGateway::SIGNATURE, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    $stored = Checkout::inTenant($link, static fn () => ProviderEvent::query()->sole());
    $raw = $stored->getRawOriginal('payload');
    $raw = is_string($raw) ? $raw : '';

    expect($stored->payload)->not->toContain('ana@example.com')
        ->and($stored->payload)->not->toContain('López')
        ->and($stored->payload_reduced)->toBeTrue()
        ->and($raw)->not->toContain('evt_Pii0001')
        ->and($raw)->not->toContain('{')
        ->and(Checkout::freshLink($link)->status->value)->toBe('paid');
});

// LOW 1 --------------------------------------------------------------------

it('checks the Turnstile hostname and action outside the testing keys', function (array $answer, bool $expected): void {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, ...$answer])]);
    config(['services.turnstile.secret_key' => 'real-secret']);

    expect(app(TurnstileVerifier::class)->verify('token', '203.0.113.9'))->toBe($expected);
})->with([
    'right host and action' => [['hostname' => 'pay.localhost', 'action' => 'checkout'], true],
    'another host' => [['hostname' => 'evil.example', 'action' => 'checkout'], false],
    'another action' => [['hostname' => 'pay.localhost', 'action' => 'login'], false],
    'testing key answer' => [['hostname' => 'example.com', 'metadata' => ['result_with_testing_key' => true]], true],
]);

it('refuses the testing-key answer in production', function (): void {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'example.com', 'metadata' => ['result_with_testing_key' => true]])]);
    config(['services.turnstile.secret_key' => 'real-secret']);
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(app(TurnstileVerifier::class)->verify('token', null))->toBeFalse();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

// LOW 2 --------------------------------------------------------------------

it('makes the sandbox refuse live-mode payments', function (): void {
    $tenant = Tenant::factory()->create();
    $live = GatewayTestHelpers::connection($tenant, true);

    expect(fn () => app(SandboxPaymentGateway::class)->inspectPaymentMethod($live, 'ctoken_sandbox_success_x'))->toThrow(GatewayRequestException::class, 'refuses live-mode');
});

// LOW 3 --------------------------------------------------------------------

it('answers malformed paths on the pay host with the checkout 404 and headers', function (string $path): void {
    Checkout::scenario();

    $response = get(payUrl($path), ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en'])->assertNotFound()->assertSee("We couldn't find this link");

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['/l/'.str_repeat('A', 200), '/l/abc/unknown', '/anything']);

it('wraps session and CSRF (419) with the checkout headers', function (): void {
    $route = Route::getRoutes()->getByName('checkout.attempts.store');
    $middleware = $route?->gatherMiddleware() ?? [];

    expect($middleware[0] ?? null)->toBe(CheckoutSecurityHeaders::class)
        ->and($middleware)->toContain('web');
});
