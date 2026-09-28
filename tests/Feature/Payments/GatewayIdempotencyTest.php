<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Payments\Services\ServerErrorRetry;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakePaymentGateway;

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
