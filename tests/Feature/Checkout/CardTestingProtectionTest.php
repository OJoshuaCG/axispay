<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Checkout\Actions\UnblockCheckout;
use App\Modules\Checkout\Notifications\CheckoutBlockedNotification;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\GatewayTestHelpers;

/**
 * Plan 11.7 and critical case 16: rate limits, Turnstile after a decline,
 * the long block after repeated declines and the tenant's unblock.
 */
beforeEach(function (): void {
    config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
});

it('asks for Turnstile after a decline and verifies it on the server before the gateway (case 16)', function (): void {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'pay.localhost', 'action' => 'checkout'])]);
    [, $link, $fake] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_decline')->assertJson(['turnstile_required' => true]);
    $callsAfterDecline = count($fake->calls);

    // Without a token: refused before any gateway call.
    Checkout::pay($link, 'ctoken_success')->assertStatus(402)->assertJson(['outcome' => 'turnstile_required', 'message' => 'Por seguridad, confirma que eres una persona antes de volver a intentar.']);
    expect($fake->calls)->toHaveCount($callsAfterDecline);
    Http::assertNothingSent();

    Checkout::pay($link, 'ctoken_success', ['turnstile_token' => 'XXXX.DUMMY.TOKEN.XXXX'])->assertOk()->assertJson(['outcome' => 'paid']);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request['secret'] === '1x0000000000000000000000000000000AA'
        && $request['response'] === 'XXXX.DUMMY.TOKEN.XXXX');
});

it('refuses a Turnstile token that Cloudflare rejects', function (): void {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    [, $link, $fake] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_decline');
    $calls = count($fake->calls);

    Checkout::pay($link, 'ctoken_success', ['turnstile_token' => 'bad'])->assertJson(['outcome' => 'turnstile_required']);
    expect($fake->calls)->toHaveCount($calls);
});

it('shows the Turnstile requirement on the page after a decline', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');

    $html = (string) \Pest\Laravel\get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->getContent();

    expect($html)->toContain('"required":true')->toContain('1x00000000000000000000AA');
});

it('pauses a link for 30 minutes after 5 confirmations in 15 minutes', function (): void {
    config(['axispay.checkout.turnstile_after_failures' => 100]);
    [, $link, $fake] = Checkout::scenario();

    foreach (range(1, 5) as $i) {
        Checkout::pay($link, 'ctoken_decline_'.$i)->assertJson(['outcome' => 'declined']);
    }

    Checkout::pay($link, 'ctoken_success')->assertStatus(429)->assertJson(['outcome' => 'rate_limited', 'retry_after_minutes' => 30]);
    expect($fake->callsTo('confirmPayment'))->toHaveCount(5);
});

it('limits confirmations per IP across links (10 per hour)', function (): void {
    config(['axispay.checkout.turnstile_after_failures' => 100, 'axispay.checkout.rate_limits.link_attempts' => 100]);
    [$tenant, $first] = Checkout::scenario();
    $second = ApiTestHelpers::link($tenant);

    foreach (range(1, 10) as $i) {
        Checkout::pay($i % 2 === 0 ? $first : $second, 'ctoken_decline_'.$i);
    }

    Checkout::pay($first, 'ctoken_success')->assertStatus(429)->assertJson(['outcome' => 'rate_limited']);
});

it('blocks the link after 10 declines, notifies the tenant and lets it unblock', function (): void {
    Notification::fake();
    config(['axispay.checkout.turnstile_after_failures' => 100, 'axispay.checkout.rate_limits.link_attempts' => 100, 'axispay.checkout.rate_limits.ip_attempts' => 100]);
    [$tenant, $link] = Checkout::scenario();
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $viewer = tenantUser($tenant, [SystemRole::Viewer]);

    foreach (range(1, 10) as $i) {
        Checkout::pay($link, 'ctoken_decline_'.$i);
    }

    $blocked = Checkout::freshLink($link);
    expect($blocked->isCheckoutBlocked())->toBeTrue()
        ->and($blocked->checkout_block_reason)->toBe('card_testing');

    Notification::assertSentTo($owner, CheckoutBlockedNotification::class);
    Notification::assertNotSentTo($viewer, CheckoutBlockedNotification::class);

    Checkout::pay($link, 'ctoken_success')->assertStatus(409)->assertJson(['outcome' => 'blocked']);
    \Pest\Laravel\get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertSee('Este enlace no acepta pagos');

    expect(fn () => Checkout::inTenant($link, static fn () => app(UnblockCheckout::class)->handleForUser($viewer, $blocked)))->toThrow(AuthorizationException::class);

    // A sensitive action: refused without a recent re-authentication (plan 17.3).
    expect(fn () => Checkout::inTenant($link, static fn () => app(UnblockCheckout::class)->handleForUser($owner, $blocked)))->toThrow(ReauthenticationRequiredException::class);

    GatewayTestHelpers::reauthenticated();
    Checkout::inTenant($link, static fn () => app(UnblockCheckout::class)->handleForUser($owner, $blocked));

    $fresh = Checkout::freshLink($link);
    expect($fresh->isCheckoutBlocked())->toBeFalse()
        ->and($fresh->checkout_unblocked_at)->not->toBeNull()
        ->and(Checkout::inTenant($link, static fn () => AuditLog::query()->whereIn('action', [AuditAction::PaymentLinkCheckoutBlocked->value, AuditAction::PaymentLinkCheckoutUnblocked->value])->count()))->toBe(2);

    Checkout::pay($link, 'ctoken_success')->assertOk()->assertJson(['outcome' => 'paid']);
});
