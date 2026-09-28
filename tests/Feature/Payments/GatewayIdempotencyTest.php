<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Jobs\CompleteAuthorizedPaymentJob;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Payments\Services\ServerErrorRetry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\CountingValidator;
use Tests\Support\FakePaymentGateway;

use function Pest\Laravel\travel;

/**
 * ADR-0051, Phase 4 iteration 6 (Stripe API correctness): a server error is
 * stored by the gateway under its idempotency key, so the call is repeated
 * under a derived key when the payment has not moved; confirmation keys
 * cover every parameter that can vary.
 */

/**
 * @return array{0: PaymentLink, 1: PaymentAttempt, 2: FakePaymentGateway}
 */
function idempotencyAuthorized(): array
{
    [, $link, $fake] = Checkout::scenario();
    $fake->failNext('capturePayment', new GatewayUnavailableException('Fake: timeout.'));
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    return [$link, $attempt, $fake];
}

it('repeats a capture that answered a stored 500 under a new key, and captures once (M2)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->serverErrorOnNext('capturePayment');

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    [$attempt] = Checkout::attempts($link);

    expect($fake->captureKeys)->toBe([IdempotencyKeys::capture($attempt->id), IdempotencyKeys::capture($attempt->id).':r1'])
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('does not repeat a capture after a 500 when the payment already moved on (M2)', function (): void {
    [$link, $attempt, $fake] = idempotencyAuthorized();
    $fake->serverErrorOnNext('capturePayment');
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    Checkout::inTenant($link, static fn () => app(CaptureAuthorizedPayment::class)->handle($attempt->id));

    expect(array_filter($fake->captureKeys, static fn (string $key): bool => str_ends_with($key, ':r1')))->toBe([])
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded);
});

it('repeats a void that answered a stored 500 under a new key (M2)', function (): void {
    [$link, $attempt, $fake] = idempotencyAuthorized();
    $fake->serverErrorOnNext('cancelPayment');

    Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, VoidReason::MerchantRejected));

    expect($fake->callsTo('cancelPayment'))->toHaveCount(2)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled);
});

it('repeats a creation that answered a stored 500 under a new key (M2)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->serverErrorOnNext('createOrUpdatePayment');

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    [$attempt] = Checkout::attempts($link);

    expect($fake->callsTo('createOrUpdatePayment'))->toBe([
        'createOrUpdatePayment:'.IdempotencyKeys::create($attempt->id),
        'createOrUpdatePayment:'.IdempotencyKeys::create($attempt->id).':r1',
    ]);
});

it('bounds the repetitions and never repeats on other errors (M2)', function (): void {
    $keys = [];
    $call = static function (string $key) use (&$keys): never {
        $keys[] = $key;

        throw new GatewayUnavailableException('Fake: 500.', 'api_error', null, 500);
    };

    expect(fn () => ServerErrorRetry::run('k', $call, static fn () => null, []))->toThrow(GatewayUnavailableException::class)
        ->and($keys)->toBe(['k', 'k:r1', 'k:r2']);

    $keys = [];
    $unreachable = static function (string $key) use (&$keys): never {
        $keys[] = $key;

        throw new GatewayUnavailableException('Fake: no answer.');
    };

    expect(fn () => ServerErrorRetry::run('k', $unreachable, static fn () => null, []))->toThrow(GatewayUnavailableException::class)
        ->and($keys)->toBe(['k']);
});

it('derives a confirmation key that covers every parameter that can vary, and stays short (L3)', function (): void {
    $token = 'ctoken_'.str_repeat('a', 250);
    $base = IdempotencyKeys::confirm('01K6AAAAAAAAAAAAAAAAAAAAAA', $token, null, 'https://pay.localhost/l/x/complete');

    expect(IdempotencyKeys::confirm('01K6AAAAAAAAAAAAAAAAAAAAAA', $token, 'ana@example.com', 'https://pay.localhost/l/x/complete'))->not->toBe($base)
        ->and(IdempotencyKeys::confirm('01K6AAAAAAAAAAAAAAAAAAAAAA', $token, null, 'https://pay.localhost/l/y/complete'))->not->toBe($base)
        ->and(IdempotencyKeys::confirm('01K6AAAAAAAAAAAAAAAAAAAAAA', $token, null, 'https://pay.localhost/l/x/complete'))->toBe($base)
        ->and(strlen($base))->toBeLessThanOrEqual(255);
});

it('locks the link, then the attempt, only inside a transaction (Q6)', function (): void {
    [$link, $attempt] = idempotencyAuthorized();

    expect(fn () => AttemptLocks::lockLinkThenAttempt($link->id, $attempt->id))->toThrow(LogicException::class);

    [$lockedLink, $lockedAttempt] = Checkout::inTenant($link, static fn () => DB::transaction(static fn (): array => AttemptLocks::lockLinkThenAttemptOrFail($link->id, $attempt->id)));

    expect($lockedLink->id)->toBe($link->id)
        ->and($lockedAttempt->id)->toBe($attempt->id)
        ->and(Checkout::inTenant($link, static fn () => PaymentAttempt::query()->activeForLink($link->id)->pluck('id')->all()))->toBe([$attempt->id]);
});

it('answers like the Stripe adapter: a payment no longer capturable is returned as it is, unless asked to refuse (T6, T11)', function (): void {
    [$link, $attempt, $fake] = idempotencyAuthorized();
    $connection = Checkout::connectionOf($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    expect($fake->capturePayment($connection, (string) $attempt->provider_payment_id, 'k-1')->status)->toBe(ProviderPaymentStatus::Succeeded);

    $fake->strictStates();

    expect(fn () => $fake->capturePayment($connection, (string) $attempt->provider_payment_id, 'k-2'))
        ->toThrow(GatewayRequestException::class)
        ->and(thrownBy(GatewayRequestException::class, fn () => $fake->retrievePayment($connection, 'pi_Unknown'))->httpStatus)->toBe(404);
});

// Judgment Day: the payer's time budget reaches capture and void ---------------

it('stops retrying a capture that answered a 5xx when the payer request runs out of time, then completes it once in the background', function (): void {
    Queue::fake([CompleteAuthorizedPaymentJob::class]);
    [, $link, $fake] = Checkout::scenario();
    $fake->serverErrorOnNext('capturePayment');
    // The capture call takes 20 s: the repetition (42 s worst case) no longer fits in the 50 s budget.
    $fake->beforeNext('capturePayment', static fn () => travel(20)->seconds());

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'processing']);

    [$attempt] = Checkout::attempts($link);
    expect($fake->captureKeys)->toBe([IdempotencyKeys::capture($attempt->id)])
        ->and($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture)
        ->and($attempt->leaseHeld())->toBeFalse();
    Queue::assertPushed(CompleteAuthorizedPaymentJob::class, static fn (CompleteAuthorizedPaymentJob $job): bool => $job->paymentAttemptId === $attempt->id);

    // The background completion (no request budget) repeats under a new key and captures once.
    Checkout::inTenant($link, static fn () => app()->call([new CompleteAuthorizedPaymentJob($attempt->id), 'handle']));

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(array_values(array_filter($fake->captureKeys, static fn (string $key): bool => str_ends_with($key, ':r1'))))->toHaveCount(1)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('stops retrying the void of a rejected authorization when the payer request runs out of time, then voids it in the background', function (): void {
    Queue::fake([CompleteAuthorizedPaymentJob::class]);
    [, $link, $fake] = Checkout::scenario();
    CountingValidator::install(PrePaymentDecision::reject('Out of stock'));
    $fake->serverErrorOnNext('cancelPayment');
    $fake->beforeNext('cancelPayment', static fn () => travel(20)->seconds());

    // Never "no charge was made" while the authorization may still stand.
    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'processing']);

    [$attempt] = Checkout::attempts($link);
    expect($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);
    Queue::assertPushed(CompleteAuthorizedPaymentJob::class);

    Checkout::inTenant($link, static fn () => app()->call([new CompleteAuthorizedPaymentJob($attempt->id), 'handle']));

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toBe([])
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('bounds the status re-read by its own request budget: a void past the capture window that answers a 5xx is left to the background', function (): void {
    Queue::fake([CompleteAuthorizedPaymentJob::class]);
    [$link, $attempt, $fake] = idempotencyAuthorized();
    $captures = count($fake->callsTo('capturePayment'));
    travel(16)->minutes();
    $fake->serverErrorOnNext('cancelPayment');
    $fake->beforeNext('cancelPayment', static fn () => travel(20)->seconds());

    Checkout::status($link)->assertOk();

    expect($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresCapture);
    Queue::assertPushed(CompleteAuthorizedPaymentJob::class);

    Checkout::inTenant($link, static fn () => app()->call([new CompleteAuthorizedPaymentJob($attempt->id), 'handle']));

    // Past the window: voided, never captured.
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toHaveCount($captures);
});

// Judgment Day round 2 ------------------------------------------------------

it('never lets a background capture retry past the attempt lease: it stops and leaves the attempt for the next check (W1)', function (): void {
    [$link, $attempt, $fake] = idempotencyAuthorized();
    $fake->serverErrorOnNext('capturePayment');
    // 50 s into the call: the repetition (42 s worst case) would end after the 85 s left in the lease.
    $fake->beforeNext('capturePayment', static fn () => travel(50)->seconds());
    $log = captureDefaultLog();

    $result = Checkout::inTenant($link, static fn () => app(CaptureAuthorizedPayment::class)->handle($attempt->id, budget: CallBudget::forJob(115)));

    expect($result->outcome)->toBe(CaptureOutcome::Pending)
        ->and(array_values(array_filter($fake->captureKeys, static fn (string $key): bool => str_ends_with($key, ':r1'))))->toBe([])
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresCapture)
        ->and(collect($log->getRecords())->contains(static fn ($record): bool => $record->level->getName() === 'WARNING' && str_contains($record->message, 'left to the webhook and the reconciliation')))->toBeTrue();
});

it('stops a background job before its own time limit (W1)', function (): void {
    [$link, $attempt, $fake] = idempotencyAuthorized();
    $calls = count($fake->callsTo('capturePayment'));

    // A job whose budget is already used up does not call the gateway at all.
    $spent = CallBudget::forJob(115);
    travel(106)->seconds();

    $result = Checkout::inTenant($link, static fn () => app(CaptureAuthorizedPayment::class)->handle($attempt->id, budget: $spent));

    expect($result->outcome)->toBe(CaptureOutcome::Pending)
        ->and($fake->callsTo('capturePayment'))->toHaveCount($calls)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresCapture);
});

it('queues one completion job per attempt however many polls and events find it busy (W2)', function (): void {
    Queue::fake([CompleteAuthorizedPaymentJob::class]);
    [$link, $attempt] = idempotencyAuthorized();
    // Another actor holds the attempt: every completion answers "pending".
    Checkout::inTenant($link, static fn () => app(AttemptLease::class)->acquire($attempt->id));

    foreach (range(1, 3) as $i) {
        Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));
        Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Checkout));
    }

    Queue::assertPushed(CompleteAuthorizedPaymentJob::class, 1);
    expect(new CompleteAuthorizedPaymentJob($attempt->id))->toBeInstanceOf(ShouldBeUnique::class);
});
