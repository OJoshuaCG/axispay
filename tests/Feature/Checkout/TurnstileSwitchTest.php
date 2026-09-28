<?php

declare(strict_types=1);

use App\Modules\Checkout\Providers\CheckoutServiceProvider;
use App\Modules\Checkout\Services\TurnstileVerifier;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;

/**
 * ADR-0052: the temporary switch that turns the bot check (Turnstile) off
 * until a Cloudflare account exists. Off: no keys needed, never required,
 * rendered or verified; every other card-testing limit stays. On (the
 * default): exactly as before, production boot guard included.
 */
it('lets production boot without the Turnstile keys only while the switch is off', function (): void {
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    config(['services.turnstile.enabled' => false]);
    CheckoutServiceProvider::assertTurnstileKeys('production');

    config(['services.turnstile.enabled' => true]);
    expect(fn () => CheckoutServiceProvider::assertTurnstileKeys('production'))->toThrow(RuntimeException::class, 'must be set in production');
});

it('is on by default', function (): void {
    expect(TurnstileVerifier::enabled())->toBeTrue();
});

it('never asks for or verifies Turnstile after a decline while switched off, and renders no widget', function (): void {
    config(['services.turnstile.enabled' => false, 'services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
    Http::fake();
    [, $link] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_decline')->assertJson(['outcome' => 'declined', 'turnstile_required' => false]);

    // No token: the next try goes straight to the gateway.
    Checkout::pay($link, 'ctoken_success')->assertOk()->assertJson(['outcome' => 'paid']);

    Http::assertNotSent(static fn (HttpRequest $request): bool => str_contains($request->url(), 'challenges.cloudflare.com'));
});

it('renders no Turnstile slot on the page while switched off', function (): void {
    config(['services.turnstile.enabled' => false]);
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');

    $html = (string) get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();

    expect(str_contains($html, 'data-turnstile-slot'))->toBeFalse()
        ->and(str_contains($html, '"required":false'))->toBeTrue();
});

it('keeps the other card-testing limits while switched off', function (): void {
    config(['services.turnstile.enabled' => false, 'axispay.checkout.rate_limits.link_attempts' => 2]);
    [, $link] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_decline_1')->assertJson(['outcome' => 'declined']);
    Checkout::pay($link, 'ctoken_decline_2')->assertJson(['outcome' => 'declined']);

    Checkout::pay($link, 'ctoken_success')->assertStatus(429)->assertJson(['outcome' => 'rate_limited']);
});

it('reports the switched-off check as a warning in the doctor, never an error, also in production', function (): void {
    config(['services.turnstile.enabled' => false, 'services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);

    Artisan::call('axispay:doctor');
    $output = Artisan::output();

    expect(str_contains($output, 'Turnstile disabled (AXISPAY_TURNSTILE_ENABLED=false)'))->toBeTrue()
        ->and(str_contains($output, 'TURNSTILE_SITE_KEY, TURNSTILE_SECRET_KEY unset'))->toBeFalse();

    app()->detectEnvironment(static fn (): string => 'production');

    try {
        Artisan::call('axispay:doctor');
        $lines = array_values(array_filter(explode("\n", Artisan::output()), static fn (string $line): bool => str_contains($line, 'Turnstile')));

        $row = implode("\n", $lines);

        expect($lines)->not->toBeEmpty()
            ->and(str_contains($row, 'WARN') && ! str_contains($row, 'ERROR'))->toBeTrue();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});
