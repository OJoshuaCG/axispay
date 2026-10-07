<?php

declare(strict_types=1);

use App\Modules\Audit\Data\Actor;
use App\Modules\Checkout\Services\ChargeAmount;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\ApplyProviderPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use App\Modules\Payments\Models\PayerDetails;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\TenantContext;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\CountingValidator;
use Tests\Support\FakePaymentGateway;

use function Pest\Laravel\get;

/**
 * Phase 4 hardening (ADR-0051, Ralph iteration 1): the link is reserved while
 * a payment is confirmed, idempotency keys never meet payer-dependent values,
 * the merchant's decision is kept, errors never reach the payer as HTTP 500,
 * stale reads and foreign amounts are not applied, and the lease has an owner.
 */
function countingValidator(PrePaymentDecision $decision): CountingValidator
{
    return CountingValidator::install($decision);
}

// Item 2 -------------------------------------------------------------------

it('reserves the link while the payment is confirmed: it can neither expire nor be canceled', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $seen = [];

    $fake->beforeNext('confirmPayment', static function () use ($link, &$seen): void {
        Checkout::inTenant($link, static function () use ($link, &$seen): void {
            $seen['status'] = PaymentLink::query()->findOrFail($link->id)->status;
            PaymentLink::query()->whereKey($link->id)->update(['expires_at' => now()->subMinute()]);
            $seen['expired'] = app(ExpirePaymentLink::class)->handle($link->id);

            try {
                app(CancelPaymentLink::class)->handle(PaymentLink::query()->findOrFail($link->id), new CancelPaymentLinkData(null), Actor::system());
                $seen['canceled'] = true;
            } catch (LinkNotCancelableException) {
                $seen['canceled'] = false;
            }
        });
    });

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    expect($seen)->toBe(['status' => PaymentLinkStatus::Processing, 'expired' => false, 'canceled' => false])
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('frees the reserved link when the confirmation fails, or expires it past its expiry', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->failNext('confirmPayment', new GatewayUnavailableException('down'));

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'processing']);
    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active)
        ->and(Checkout::attempts($link)[0]->leaseHeld())->toBeFalse();

    $fake->beforeNext('confirmPayment', static fn () => Checkout::inTenant($link, static fn () => PaymentLink::query()->whereKey($link->id)->update(['expires_at' => now()->subMinute()])));
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline')->assertJson(['outcome' => 'declined']);

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Expired)
        // Its waiting payment is canceled once the confirmation let go (one decline: failed).
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Failed);
});

it('voids instead of capturing when the link was closed meanwhile', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->beforeNext('capturePayment', static fn () => null);
    app()->instance(PrePaymentValidator::class, new class($link) implements PrePaymentValidator
    {
        public function __construct(private readonly PaymentLink $link) {}

        public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
        {
            // Closed behind the reservation (e.g. an operator's data fix).
            PaymentLink::query()->whereKey($this->link->id)->update(['status' => PaymentLinkStatus::Canceled->value]);

            return PrePaymentDecision::approve();
        }
    });

    Checkout::pay($link);

    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toBe([])
        ->and($fake->callsTo('cancelPayment'))->toHaveCount(1);
});

it('lets the payment win when the void of a closed link finds it already succeeded', function (): void {
    [, $link, $fake] = Checkout::scenario();
    app()->instance(PrePaymentValidator::class, new class($link, $fake) implements PrePaymentValidator
    {
        public function __construct(private readonly PaymentLink $link, private readonly FakePaymentGateway $fake) {}

        public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
        {
            PaymentLink::query()->whereKey($this->link->id)->update(['status' => PaymentLinkStatus::Canceled->value]);
            $this->fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

            return PrePaymentDecision::approve();
        }
    });

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid)
        ->and(Checkout::attempts($link)[0]->late_payment)->toBeTrue();
});

it('makes the void wait for the lease holder', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds');
    [$attempt] = Checkout::attempts($link);
    Checkout::inTenant($link, static fn () => app(AttemptLease::class)->acquire($attempt->id));

    expect(fn () => Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, VoidReason::LinkClosed)))->toThrow(AttemptBusyException::class)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresAction);
});

// Item 3 -------------------------------------------------------------------

it('recovers from a lost create answer even when the payer changes the e-mail (fixed-parameter key)', function (): void {
    [$tenant, $link, $fake] = Checkout::scenario();
    $tenant->forceFill(['settings' => ['checkout' => ['send_stripe_receipts' => true]]])->save();
    $fake->loseNextResponse('createOrUpdatePayment');

    Checkout::pay($link, 'ctoken_success_1', ['payer' => ['email' => 'first@example.com']])->assertStatus(503);
    Checkout::pay($link, 'ctoken_success_2', ['payer' => ['email' => 'second@example.com']])->assertOk()->assertJson(['outcome' => 'paid']);

    [$attempt] = Checkout::attempts($link);
    expect($fake->callsTo('createOrUpdatePayment'))->toBe(array_fill(0, 2, 'createOrUpdatePayment:axispay:create_pi:'.$attempt->id))
        ->and($fake->receiptEmails)->toBe(['second@example.com']);
});

it('updates the payment with an amount-bound key when the amount to charge changed (Phase 6 path)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);

    app()->instance(ChargeAmount::class, new class extends ChargeAmount
    {
        public function for(PaymentLink $link, PaymentMethodPreview $card): Money
        {
            return Money::ofMinor(99_900, $link->currency);
        }
    });

    Checkout::pay($link, 'ctoken_success')->assertOk()->assertJson(['outcome' => 'paid']);

    expect($fake->callsTo('createOrUpdatePayment'))->toContain('createOrUpdatePayment:axispay:update_pi:'.$attempt->id.':99900USD')
        ->and(Checkout::attempts($link)[0]->amount_minor)->toBe(99_900);
});

// Item 4 -------------------------------------------------------------------

it('answers processing, never a 500, when the capture cannot be done now', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->failNext('capturePayment', new GatewayUnavailableException('down'));

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'processing']);

    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture)
        ->and($attempt->validation_outcome)->toBe(ValidationOutcome::NotConfigured)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Processing);
});

it('keeps a rejection: a failed void is retried as a void, and the merchant is not asked again', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $validator = countingValidator(PrePaymentDecision::reject('Sin stock.'));
    $fake->failNext('cancelPayment', new GatewayUnavailableException('down'));

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'processing']);
    [$attempt] = Checkout::attempts($link);
    expect($attempt->validation_outcome)->toBe(ValidationOutcome::Rejected)
        ->and($attempt->validation_payer_message)->toBe('Sin stock.');

    // A webhook arrives later.
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toBe([])
        ->and($validator->calls)->toBe(1);
});

// Item 5 -------------------------------------------------------------------

it('collects no payer data when the tenant has no privacy notice', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->state(['payer_fields_config' => ['email' => 'required', 'full_name' => 'hidden', 'phone' => 'hidden', 'company_name' => 'hidden', 'billing_address' => 'hidden', 'tax_id' => 'hidden', 'notes' => 'hidden']]));
    Checkout::removeLegalDocument($tenant, LegalDocumentKind::Privacy);

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertDontSee('payer[email]', false)->assertDontSee('Tus datos');
    Checkout::pay($link, body: ['payer' => []])->assertOk()->assertJson(['outcome' => 'paid']);

    expect(Checkout::inTenant($link, static fn () => PayerDetails::query()->count()))->toBe(0);
});

// Item 6 -------------------------------------------------------------------

it('stores declines and payer data with the mode of their attempt (rule 4)', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');

    $failure = Checkout::inTenant($link, static fn () => PaymentAttemptFailure::query()->sole());
    $payer = Checkout::inTenant($link, static fn () => PayerDetails::query()->sole());

    expect($failure->livemode)->toBeFalse()->and($payer->livemode)->toBeFalse()
        ->and(app(TenantContext::class)->runAsTenant($link->tenant_id, true, static fn () => [PaymentAttemptFailure::query()->count(), PayerDetails::query()->count()]))->toBe([0, 0]);
});

// Item 7 -------------------------------------------------------------------

it('shows the amount only while the link can be paid or to the session that paid it', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['amount_minor' => 123_456, 'currency' => 'MXN']));
    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertSee('1,234.56', false);

    Checkout::pay($link)->assertOk();
    get(payUrl('/l/'.$link->public_token.'/complete'), ['User-Agent' => 'Mozilla/5.0'])->assertSee('Pago realizado')->assertSee('1,234.56', false);

    \Pest\Laravel\flushSession();
    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertSee('Este cobro ya fue pagado')->assertDontSee('1,234.56', false);

    [, $expired] = Checkout::scenario(static fn ($f) => $f->expired()->state(['amount_minor' => 123_456, 'currency' => 'MXN']));
    get(payUrl('/l/'.$expired->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertDontSee('1,234.56', false);
});

// Items 8 and 9 ------------------------------------------------------------

it('does not apply a stale backward read, but accepts it from the lease holder', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    $stale = new ProviderPayment((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresAction, $attempt->amount_minor, $attempt->currency->value, attemptReference: $attempt->id);

    Checkout::inTenant($link, static fn () => app(ApplyProviderPayment::class)->handle($attempt->id, $stale));
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    $token = Checkout::inTenant($link, static fn () => app(AttemptLease::class)->acquire($attempt->id));
    Checkout::inTenant($link, static fn () => app(ApplyProviderPayment::class)->handle($attempt->id, $stale, leaseToken: $token));
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresAction);
});

it('refuses a gateway payment whose amount or currency differ from the attempt', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_processing');
    [$attempt] = Checkout::attempts($link);

    $foreign = new ProviderPayment((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded, 1, 'USD', attemptReference: $attempt->id);
    Checkout::inTenant($link, static fn () => app(ApplyProviderPayment::class)->handle($attempt->id, $foreign));

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Processing)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Processing);
});

// Items 11 and 12 ----------------------------------------------------------

it('returns the return URL, with its signed proof (ADR-0064), in the status once paid', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['return_url' => 'https://demo.test/gracias']));

    Checkout::status($link)->assertExactJson(['state' => 'active']);
    Checkout::pay($link);

    $status = Checkout::status($link)->assertJsonPath('state', 'paid');
    $polled = $status->json('return_url');
    $returnUrl = is_string($polled) ? $polled : '';

    expect($returnUrl)->toStartWith('https://demo.test/gracias?plink='.$link->prefixedId().'&payment=pay_')
        ->and($returnUrl)->toContain('&status=paid&ts=')
        ->and($returnUrl)->toMatch('/&sig=[0-9a-f]{64}$/');
});

it('releases a lease only with its own token', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);
    $lease = app(AttemptLease::class);

    $token = Checkout::inTenant($link, static fn () => $lease->acquire($attempt->id));
    Checkout::inTenant($link, static fn () => $lease->release($attempt->id, 'not-the-token'));
    expect(Checkout::attempts($link)[0]->leaseHeld())->toBeTrue()
        ->and(Checkout::inTenant($link, static fn () => $lease->acquire($attempt->id)))->toBeNull();

    Checkout::inTenant($link, static fn () => $lease->release($attempt->id, (string) $token));
    expect(Checkout::attempts($link)[0]->leaseHeld())->toBeFalse();
});

// Item 1 (checkout side) ---------------------------------------------------

it('stops taking payments for a closed tenant but keeps them for a suspended one (plan 21.3, ADR-013)', function (): void {
    [$tenant, $link, $fake] = Checkout::scenario();
    $tenant->forceFill(['status' => 'suspended'])->save();
    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertSee('data-pay-button', false);

    Tenant::query()->whereKey($tenant->id)->update(['status' => 'closed', 'closed_at' => now()]);
    app(TenantAccess::class)->forget($tenant->id);

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertSee('Este enlace de pago ya no está disponible')->assertDontSee('data-pay-button', false);
    Checkout::pay($link)->assertStatus(409)->assertJson(['outcome' => 'canceled']);
    expect($fake->calls)->toBe([]);
});
