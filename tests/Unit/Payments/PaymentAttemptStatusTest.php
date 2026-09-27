<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ProviderPaymentStatus as P;
use App\Modules\Payments\Enums\PaymentAttemptStatus as S;
use App\Modules\Payments\Services\PaymentAttemptStateMachine;

/**
 * Plan 9.2 with the authorized stage of ADR-0050: the mapping from the
 * gateway's status and the transition table, exhaustively.
 */
it('maps every gateway status to an attempt status', function (P $provider, int $failures, S $expected): void {
    expect(S::fromProvider($provider, $failures))->toBe($expected);
})->with([
    [P::RequiresPaymentMethod, 0, S::RequiresPaymentMethod],
    [P::RequiresConfirmation, 0, S::RequiresConfirmation],
    [P::RequiresAction, 0, S::RequiresAction],
    [P::RequiresCapture, 0, S::RequiresCapture],
    [P::Processing, 0, S::Processing],
    [P::Succeeded, 0, S::Succeeded],
    // Closed without declines: canceled; after at least one: failed (plan 9.2).
    [P::Canceled, 0, S::Canceled],
    [P::Canceled, 2, S::Failed],
]);

it('counts authorized-not-captured as active and in flight (one active attempt per link)', function (): void {
    expect(S::activeValues())->toBe(['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture', 'processing'])
        ->and(S::RequiresCapture->isInFlight())->toBeTrue()
        ->and(S::RequiresPaymentMethod->isInFlight())->toBeFalse();
});

it('allows any move between active statuses and none out of a final one', function (S $from, S $to): void {
    $expected = ! $from->isTerminal() && $from !== $to;

    expect(PaymentAttemptStateMachine::canTransition($from, $to))->toBe($expected);
})->with(fn (): array => array_merge(...array_map(
    static fn (S $from): array => array_map(static fn (S $to): array => [$from, $to], S::cases()),
    S::cases(),
)));
