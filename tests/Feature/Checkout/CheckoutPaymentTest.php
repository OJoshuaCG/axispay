<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Enums\ProviderFailureKind;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PayerDetails;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\travel;

/**
 * The payment flow of the checkout (plan 11.4, ADR-0050, ADR-0051) against
 * FakePaymentGateway: authorize with manual capture, validation hook, capture.
 */
it('authorizes, captures and marks the link paid (success)', function (): void {
    [, $link, $fake] = Checkout::scenario();

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid'])->assertJsonPath('redirect_url', 'https://pay.localhost/l/'.$link->public_token.'/complete');

    [$attempt] = Checkout::attempts($link);
    $fresh = Checkout::freshLink($link);

    expect($attempt->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->authorized_at)->not->toBeNull()
        ->and($attempt->succeeded_at)->not->toBeNull()
        ->and($attempt->card_brand)->toBe('visa')
        ->and($attempt->card_last4)->toBe('4242')
        ->and($attempt->card_country)->toBe('MX')
        ->and($attempt->amount_minor)->toBe($link->amount_minor)
        ->and($fresh->status)->toBe(PaymentLinkStatus::Paid)
        ->and($fresh->paid_at)->not->toBeNull()
        ->and($fake->callsTo('createOrUpdatePayment'))->toBe(['createOrUpdatePayment:axispay:create_pi:'.$attempt->id])
        ->and($fake->callsTo('capturePayment'))->toHaveCount(1);

    $types = Checkout::inTenant($link, static fn () => DomainEvent::query()->get()->map(static fn (DomainEvent $event): string => $event->type->value)->all());
    expect($types)->toContain(DomainEventType::PaymentSucceeded->value, DomainEventType::PaymentLinkPaid->value);
});

it('declines with a generic message, records the decline and keeps the link payable', function (): void {
    [, $link] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_decline')
        ->assertStatus(402)
        ->assertJson(['outcome' => 'declined', 'message' => 'La tarjeta fue rechazada. Intenta con otra o contacta a tu banco.', 'turnstile_required' => true])
        ->assertJsonMissingPath('client_secret');

    [$attempt] = Checkout::attempts($link);
    $failures = Checkout::inTenant($link, static fn () => PaymentAttemptFailure::query()->get());

    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresPaymentMethod)
        ->and($attempt->failure_count)->toBe(1)
        ->and($attempt->last_decline_code)->toBe('generic_decline')
        ->and($failures)->toHaveCount(1)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('reuses the same attempt and gateway payment after a decline (plan 9.2)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    config(['services.turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'pay.localhost', 'action' => 'checkout'])]);

    Checkout::pay($link, 'ctoken_decline');
    Checkout::pay($link, 'ctoken_success_2', ['turnstile_token' => 'XXXX.DUMMY.TOKEN.XXXX'])->assertOk()->assertJson(['outcome' => 'paid']);

    $attempts = Checkout::attempts($link);

    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempts[0]->failure_count)->toBe(1)
        ->and($fake->callsTo('createOrUpdatePayment'))->toHaveCount(1)
        ->and($fake->callsTo('confirmPayment'))->toHaveCount(2);
});

it('hands the client secret for 3D Secure, then completes after the bank', function (): void {
    [, $link, $fake] = Checkout::scenario();

    $response = Checkout::pay($link, 'ctoken_threeds')->assertOk()->assertJson(['outcome' => 'requires_action']);
    [$attempt] = Checkout::attempts($link);

    expect($response->json('client_secret'))->toBe($attempt->provider_payment_id.'_secret_fake')
        ->and($attempt->status)->toBe(PaymentAttemptStatus::RequiresAction)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Processing)
        // The secret is handed to the browser, never stored.
        ->and(json_encode(Checkout::attempts($link)[0]->getAttributes()))->not->toContain('_secret_');

    // The bank approved: the gateway authorized the payment.
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresCapture);

    Checkout::continue($link)->assertOk()->assertJson(['outcome' => 'paid']);
    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('reports a failed bank verification and leaves the link payable', function (): void {
    [, $link, $fake] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_threeds');
    [$attempt] = Checkout::attempts($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresPaymentMethod, new ProviderPaymentFailure('ch_auth_1', 'payment_intent_authentication_failure', null, 'Authentication failed.', ProviderFailureKind::AuthenticationFailed));

    Checkout::continue($link)->assertStatus(402)->assertJson(['outcome' => 'authentication_failed']);

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active)
        ->and(Checkout::attempts($link)[0]->failure_count)->toBe(1);
});

it('keeps the link processing while the gateway processes the payment', function (): void {
    [, $link] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_processing')->assertOk()->assertJson(['outcome' => 'processing']);

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Processing);
    Checkout::status($link)->assertOk()->assertJson(['state' => 'processing']);
});

it('refuses a second payment while one is under way (another tab)', function (): void {
    [, $link, $fake] = Checkout::scenario();

    Checkout::pay($link, 'ctoken_threeds');
    Checkout::pay($link, 'ctoken_success')->assertStatus(409)->assertJson(['outcome' => 'in_progress']);

    expect(Checkout::attempts($link))->toHaveCount(1)
        ->and($fake->callsTo('confirmPayment'))->toHaveCount(1);
});

it('refuses a payment while another request holds the attempt (lease)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);

    Checkout::inTenant($link, static fn () => PaymentAttempt::query()->whereKey($attempt->id)->update(['confirmation_lease_until' => now()->addMinute()]));

    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_success')->assertStatus(409)->assertJson(['outcome' => 'in_progress']);
    expect($fake->callsTo('confirmPayment'))->toHaveCount(1);
});

it('answers closed links without touching the gateway', function (PaymentLinkStatus $status, string $outcome): void {
    [, $link, $fake] = Checkout::scenario(static fn ($f) => $f->inStatus($status));

    Checkout::pay($link)->assertStatus(409)->assertJson(['outcome' => $outcome])->assertJsonPath('redirect_url', 'https://pay.localhost/l/'.$link->public_token.'/complete');

    expect($fake->calls)->toBe([]);
})->with([
    [PaymentLinkStatus::Paid, 'already_paid'],
    [PaymentLinkStatus::Expired, 'expired'],
    [PaymentLinkStatus::Canceled, 'canceled'],
]);

it('validates the payer fields before anything else (422, no gateway call)', function (): void {
    [, $link, $fake] = Checkout::scenario(static fn ($f) => $f->state(['payer_fields_config' => ['email' => 'required', 'full_name' => 'required', 'phone' => 'optional', 'company_name' => 'hidden', 'billing_address' => 'hidden', 'tax_id' => 'hidden', 'notes' => 'hidden']]));

    Checkout::pay($link, body: ['payer' => ['email' => 'not-an-email', 'full_name' => '', 'phone' => '123']])
        ->assertStatus(422)
        ->assertJson(['outcome' => 'invalid_fields'])
        ->assertJsonStructure(['errors' => ['email', 'full_name', 'phone']])
        ->assertJsonMissing(['not-an-email']);

    expect($fake->calls)->toBe([])->and(Checkout::attempts($link))->toBe([]);
});

it('stores the payer data encrypted and never writes it to the logs', function (): void {
    $log = captureDefaultLog();
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['payer_fields_config' => ['email' => 'required', 'full_name' => 'optional', 'phone' => 'optional', 'company_name' => 'hidden', 'billing_address' => 'hidden', 'tax_id' => 'hidden', 'notes' => 'hidden']]));

    Checkout::pay($link, 'ctoken_decline', ['payer' => ['email' => 'Ana.Lopez@Example.com', 'full_name' => 'Ana López Pérez', 'phone' => '55 1234 5678', 'phone_country' => 'MX']]);

    [$attempt] = Checkout::attempts($link);
    $details = Checkout::inTenant($link, static fn () => PayerDetails::query()->where('payment_attempt_id', $attempt->id)->sole());
    $raw = $details->getRawOriginal('data');
    $raw = is_string($raw) ? $raw : '';

    expect($raw)->not->toBe('');
    expect($raw)->not->toContain('ana.lopez');
    expect($raw)->not->toContain('López');
    expect($details->data)->toBe(['email' => 'ana.lopez@example.com', 'full_name' => 'Ana López Pérez', 'phone' => '+525512345678']);
    expect($details->purge_after)->not->toBeNull();
    expect($details->toArray())->not->toHaveKey('data');

    foreach ($log->getRecords() as $record) {
        $line = (string) json_encode([$record->message, $record->context]);
        expect($line)->not->toContain('Ana');
        expect($line)->not->toContain('5512345678');
        expect($line)->not->toContain('example.com');
    }
});

it('voids the authorization when the merchant rejects the payment (ADR-0050 reject path)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    app()->bind(PrePaymentValidator::class, static fn () => new class implements PrePaymentValidator
    {
        public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
        {
            expect(Transactions::open())->toBeFalse(); // rule 7b: never inside a transaction

            return PrePaymentDecision::reject('Sin existencias del artículo.');
        }
    });

    Checkout::pay($link)->assertStatus(402)->assertJson(['outcome' => 'merchant_rejected', 'payer_message' => 'Sin existencias del artículo.']);

    [$attempt] = Checkout::attempts($link);

    expect($attempt->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toBe([])
        ->and($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and($fake->paymentStatus((string) $attempt->provider_payment_id))->toBe(ProviderPaymentStatus::Canceled)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('uses stable idempotency keys: a retry after a network error confirms the same payment once', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->failNext('confirmPayment', new GatewayUnavailableException('Stripe is unavailable.'));

    // A confirmation without an answer may be under way: "processing", never an error.
    Checkout::pay($link, 'ctoken_success_same')->assertOk()->assertJson(['outcome' => 'processing']);
    Checkout::pay($link, 'ctoken_success_same')->assertOk()->assertJson(['outcome' => 'paid']);

    [$attempt] = Checkout::attempts($link);
    expect($fake->callsTo('createOrUpdatePayment'))->toBe(['createOrUpdatePayment:axispay:create_pi:'.$attempt->id])
        ->and($fake->callsTo('confirmPayment'))->toHaveCount(2)
        ->and(Checkout::attempts($link))->toHaveCount(1)
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Succeeded);
});

it('refuses when the tenant cannot charge in the mode', function (): void {
    [, $link, $fake] = Checkout::scenario(connection: static fn ($f) => $f->onboarding());

    Checkout::pay($link)->assertStatus(503)->assertJson(['outcome' => 'unavailable']);
    expect($fake->calls)->toBe([]);
});

it('answers 404 for an unknown token on every endpoint', function (): void {
    Checkout::scenario();

    \Pest\Laravel\postJson(payUrl('/l/unknownToken0000000000000000/attempts'), ['confirmation_token' => 'ctoken_success'])->assertNotFound()->assertJson(['outcome' => 'not_found']);
    \Pest\Laravel\getJson(payUrl('/l/unknownToken0000000000000000/status'))->assertNotFound();
});

it('shows "Payment complete" on the completion page only to the session that paid', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['return_url' => 'https://demo.test/gracias']));

    Checkout::pay($link)->assertOk();

    \Pest\Laravel\get(payUrl('/l/'.$link->public_token.'/complete'), ['User-Agent' => 'Mozilla/5.0'])
        ->assertOk()
        ->assertSee('Pago realizado')
        ->assertSee('https://demo.test/gracias', false)
        ->assertSee('rel="noopener noreferrer"', false);

    \Pest\Laravel\flushSession();

    \Pest\Laravel\get(payUrl('/l/'.$link->public_token.'/complete'), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertSee('Este cobro ya fue pagado');
});

// Request time budget (ADR-0051) ---------------------------------------------

it('stops before confirming when Stripe was slow and no time is left: nothing is charged', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->beforeNext('createOrUpdatePayment', static fn () => travel(20)->seconds());

    Checkout::pay($link)->assertJson(['outcome' => 'error']);

    expect($fake->callsTo('confirmPayment'))->toBe([])
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('answers processing when Stripe was slow and no time is left to capture; the status poll completes it', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->beforeNext('confirmPayment', static fn () => travel(46)->seconds());

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'processing']);

    [$attempt] = Checkout::attempts($link);
    expect($fake->callsTo('capturePayment'))->toBe([])
        ->and($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    // Later, the page polls: the payment is re-read and captured once.
    travel(10)->seconds();
    Checkout::status($link)->assertOk();

    expect($fake->callsTo('capturePayment'))->toHaveCount(1)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded);
});
