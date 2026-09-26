<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus as S;
use App\Modules\PaymentLinks\Exceptions\InvalidStateTransition;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * The transition table of plan 9.1, exhaustively, and the rule that
 * transitions only run inside a transaction (no database needed: this suite
 * has no RefreshDatabase transaction around it).
 */
it('allows exactly the transitions of plan 9.1', function (S $from, S $to, bool $allowed): void {
    expect(PaymentLinkStateMachine::canTransition($from, $to))->toBe($allowed);
})->with(function (): array {
    $allowed = [
        'active' => ['processing', 'paid', 'expired', 'canceled'],
        'processing' => ['active', 'paid', 'expired'],
        'expired' => ['paid'],
        'canceled' => ['paid'],
        'paid' => [],
    ];
    $cases = [];

    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            $cases["{$from->value} -> {$to->value}"] = [$from, $to, in_array($to->value, $allowed[$from->value], true)];
        }
    }

    return $cases;
});

it('forbids canceling a link with a payment in progress and leaving paid', function (): void {
    expect(PaymentLinkStateMachine::canTransition(S::Processing, S::Canceled))->toBeFalse();

    foreach (S::cases() as $to) {
        expect(PaymentLinkStateMachine::canTransition(S::Paid, $to))->toBeFalse();
    }
});

it('refuses to apply a transition outside a transaction', function (): void {
    expect(DB::transactionLevel())->toBe(0);

    $link = new PaymentLink;
    $link->forceFill(['id' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9S', 'status' => S::Active]);

    expect(fn () => app(PaymentLinkStateMachine::class)->cancel($link, null))
        ->toThrow(InvalidStateTransition::class, 'must run inside a transaction on a locked row')
        ->and(fn () => app(PaymentLinkStateMachine::class)->expire($link))
        ->toThrow(InvalidStateTransition::class, 'must run inside a transaction on a locked row');

    expect($link->status)->toBe(S::Active);
});
