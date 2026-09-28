<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Checkout\Services\ChargeAmount;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Jobs\CancelLinksOfClosedTenantJob;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ReviewReason;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Shared\Money\Money;
use Stripe\HttpClient\CurlClient;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakePaymentGateway;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;

/**
 * Phase 4 concurrency and double-charge hardening (ADR-0051, Ralph
 * iteration 3), in-process. The multi-process races are in
 * tests/Feature/Concurrency/CheckoutRaceTest.php.
 */

// H1 -----------------------------------------------------------------------

it('cancels a 3D Secure step the payer abandoned and frees the link', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds');
    [$attempt] = Checkout::attempts($link);

    travel(15)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresAction);

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->paymentStatus((string) $attempt->provider_payment_id))->toBe(ProviderPaymentStatus::Canceled)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

// H2 -----------------------------------------------------------------------

it('stops a capture whose lease expired during the merchant call (another actor holds it)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    $captures = count($fake->callsTo('capturePayment'));
    PaymentAttempt::query()->withoutGlobalScopes()->whereKey($attempt->id)->update(['validation_outcome' => null]);

    app()->instance(PrePaymentValidator::class, new class($attempt->id) implements PrePaymentValidator
    {
        public function __construct(private readonly string $attemptId) {}

        public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
        {
            // The merchant was slow: the lease expired and another actor took it.
            PaymentAttempt::query()->whereKey($this->attemptId)->update(['confirmation_lease_until' => now()->subSecond()]);
            app(AttemptLease::class)->acquire($this->attemptId);

            return PrePaymentDecision::approve();
        }
    });

    $result = Checkout::inTenant($link, static fn () => app(CaptureAuthorizedPayment::class)->handle($attempt->id));

    expect($result->outcome)->toBe(CaptureOutcome::Pending)
        ->and($fake->callsTo('capturePayment'))->toHaveCount($captures);
});

it('refuses a void whose lease was lost before the gateway call', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds');
    [$attempt] = Checkout::attempts($link);
    $lease = app(AttemptLease::class);

    $stale = Checkout::inTenant($link, static fn () => $lease->acquire($attempt->id));
    Checkout::inTenant($link, static fn () => PaymentAttempt::query()->whereKey($attempt->id)->update(['confirmation_lease_until' => now()->subSecond()]));
    Checkout::inTenant($link, static fn () => $lease->acquire($attempt->id)); // the next actor

    expect(fn () => Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, VoidReason::LinkClosed, $stale)))->toThrow(AttemptBusyException::class)
        ->and($fake->callsTo('cancelPayment'))->toBe([]);
});

it('uses the decision another actor stored first', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    PaymentAttempt::query()->withoutGlobalScopes()->whereKey($attempt->id)->update(['validation_outcome' => null]);

    app()->instance(PrePaymentValidator::class, new class($attempt->id) implements PrePaymentValidator
    {
        public function __construct(private readonly string $attemptId) {}

        public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
        {
            // A concurrent actor asked first and got a rejection.
            PaymentAttempt::query()->whereKey($this->attemptId)->update(['validation_outcome' => 'rejected']);

            return PrePaymentDecision::approve();
        }
    });
    $fake->capturesAs(ProviderPaymentStatus::Succeeded);
    $captures = count($fake->callsTo('capturePayment'));

    Checkout::inTenant($link, static fn () => app(CaptureAuthorizedPayment::class)->handle($attempt->id));

    expect($fake->callsTo('capturePayment'))->toHaveCount($captures)
        ->and($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled);
});

it('bounds every Stripe call below the attempt lease', function (): void {
    config(['services.stripe.test.secret' => 'sk_test_platformdummy']);
    [, $link] = Checkout::scenario();
    app(StripeClientFactory::class)->for(Checkout::connectionOf($link));
    $http = CurlClient::instance();
    $retries = config()->integer('services.stripe.max_network_retries');

    if (! $http instanceof CurlClient) {
        throw new LogicException('The SDK client must be the curl client.');
    }

    $timeout = $http->getTimeout();
    $timeout = is_int($timeout) ? $timeout : 0;

    expect($timeout)->toBe(20)
        ->and($http->getConnectTimeout())->toBe(5)
        ->and((1 + $retries) * $timeout + 2)->toBeLessThan(config()->integer('axispay.checkout.confirmation_lease_seconds'));
});

// M1 -----------------------------------------------------------------------

it('does not move an in-flight attempt back on an old read without a new decline', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds');
    [$attempt] = Checkout::attempts($link);

    // An old "requires_payment_method" read (before 3D Secure) arrives late.
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresPaymentMethod);
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresAction);

    // A real failure carries a new decline and applies.
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresPaymentMethod, new ProviderPaymentFailure('ch_new_1', 'card_declined', 'do_not_honor', 'Declined.'));
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresPaymentMethod)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

// M3 -----------------------------------------------------------------------

it('takes over a reservation left by a confirmation that died', function (): void {
    [, $link] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);

    // The worker died after reserving the link and taking the lease.
    Checkout::inTenant($link, static function () use ($link, $attempt): void {
        PaymentLink::query()->whereKey($link->id)->update(['status' => PaymentLinkStatus::Processing->value]);
        PaymentAttempt::query()->whereKey($attempt->id)->update(['confirmation_lease_until' => now()->addMinute(), 'confirmation_lease_token' => str_repeat('a', 32)]);
    });
    Checkout::pay($link, 'ctoken_success')->assertStatus(409)->assertJson(['outcome' => 'in_progress']);

    travel(2)->minutes(); // the dead lease expires
    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertSee('data-pay-button', false);
    Checkout::pay($link, 'ctoken_success')->assertOk()->assertJson(['outcome' => 'paid']);

    expect(Checkout::attempts($link))->toHaveCount(1);
});

// L1 -----------------------------------------------------------------------

it('continues from the current state when a confirmation is retried after a lost answer', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture)->loseNextResponse('confirmPayment');

    // The answer was lost: the payment may be under way, so the page says processing, never an error.
    Checkout::pay($link, 'ctoken_success_first')->assertOk()->assertJson(['outcome' => 'processing']);
    [$attempt] = Checkout::attempts($link);
    expect($fake->paymentStatus((string) $attempt->provider_payment_id))->toBe(ProviderPaymentStatus::RequiresCapture);

    $fake->capturesAs(ProviderPaymentStatus::Succeeded);
    Checkout::pay($link, 'ctoken_success_second')->assertOk()->assertJson(['outcome' => 'paid']);

    expect($fake->callsTo('capturePayment'))->toHaveCount(1)
        ->and(Checkout::attempts($link))->toHaveCount(1);
});

// L2 -----------------------------------------------------------------------

it('recreates with the stored amount and then updates, when the amount changed after a lost create', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->loseNextResponse('createOrUpdatePayment');
    Checkout::pay($link)->assertStatus(503);
    [$attempt] = Checkout::attempts($link);

    app()->instance(ChargeAmount::class, new class extends ChargeAmount
    {
        public function for(PaymentLink $link, PaymentMethodPreview $card): Money
        {
            return Money::ofMinor(99_900, $link->currency);
        }
    });

    Checkout::pay($link, 'ctoken_success_2')->assertOk()->assertJson(['outcome' => 'paid']);

    expect($fake->callsTo('createOrUpdatePayment'))->toBe([
        'createOrUpdatePayment:axispay:create_pi:'.$attempt->id,
        'createOrUpdatePayment:axispay:create_pi:'.$attempt->id,
        'createOrUpdatePayment:axispay:update_pi:'.$attempt->id.':99900USD',
    ]);
});

// L3 -----------------------------------------------------------------------

it('flags for review an attempt closed without the gateway', function (): void {
    [, $link] = Checkout::scenario(connection: static fn ($f) => $f->apiKey());
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);
    // The api_key connection was disconnected: its credentials are gone.
    Checkout::inTenant($link, static fn () => GatewayConnection::query()->whereKey($attempt->gateway_connection_id)->update(['credentials_secret' => null]));
    FakePaymentGatewayThatNeedsKeys::install();

    $closed = Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, VoidReason::LinkClosed));

    expect($closed->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($closed->needs_review)->toBeTrue()
        ->and($closed->review_reason)->toBe(ReviewReason::ClosedWithoutGateway)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentNeedsReview->value)->count())->toBe(1);
});

// L4 -----------------------------------------------------------------------

it('bounds the closed-tenant cancellation by time, not tries', function (): void {
    [, $link] = Checkout::scenario();
    $job = Checkout::inTenant($link, static fn () => new CancelLinksOfClosedTenantJob);

    expect(property_exists($job, 'tries') && isset($job->tries))->toBeFalse()
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addDay()->getTimestamp());
});

/** The fake gateway, but its cancel needs the connection's keys like the Stripe adapter. */
final class FakePaymentGatewayThatNeedsKeys
{
    public static function install(): void
    {
        $fake = FakePaymentGateway::install();
        $fake->failNext('cancelPayment', new GatewayConfigurationException('The connection has no stored credentials.'));
    }
}
