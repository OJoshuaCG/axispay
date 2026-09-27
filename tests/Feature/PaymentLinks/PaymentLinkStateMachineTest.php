<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus as S;
use App\Modules\PaymentLinks\Exceptions\InvalidStateTransition;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestHelpers;

/**
 * The transition table of plan 9.1, exhaustively.
 */
it('applies expire and cancel on a locked row inside a transaction', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);
            app(PaymentLinkStateMachine::class)->cancel($locked, 'Customer asked');
        });
    });

    $fresh = ApiTestHelpers::freshLink($link->id);
    expect($fresh->status)->toBe(S::Canceled)->and($fresh->cancel_reason)->toBe('Customer asked')->and($fresh->canceled_at)->not->toBeNull();
});

it('refuses a forbidden transition and keeps the status', function (S $state): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: ApiTestHelpers::inStatus($state));

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            expect(fn () => app(PaymentLinkStateMachine::class)->cancel($locked, null))->toThrow(InvalidStateTransition::class);
        });
    });

    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe($state);
})->with([S::Paid, S::Expired, S::Processing]);

it('applies the attempt-driven transitions (Phase 4)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $machine = app(PaymentLinkStateMachine::class);
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            expect($machine->enterProcessing($locked)->status)->toBe(S::Processing)
                ->and($machine->resumeAfterAttempt($locked)->status)->toBe(S::Active)
                ->and($machine->markPaid($locked))->toBeFalse()
                ->and($locked->status)->toBe(S::Paid)
                ->and($locked->paid_at)->not->toBeNull();
        });
    });
});

it('expires instead of reopening when the attempt fails after the expiry', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->processing()->pastExpiry());

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            expect(app(PaymentLinkStateMachine::class)->resumeAfterAttempt($locked)->status)->toBe(S::Expired);
        });
    });
});

it('reports a late payment on an expired or canceled link (the payment wins)', function (S $closed): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: ApiTestHelpers::inStatus($closed));

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            expect(app(PaymentLinkStateMachine::class)->markPaid($locked))->toBeTrue()->and($locked->status)->toBe(S::Paid);
        });
    });
})->with([S::Expired, S::Canceled]);

it('never leaves paid', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: ApiTestHelpers::inStatus(S::Paid));

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            expect(fn () => app(PaymentLinkStateMachine::class)->enterProcessing($locked))->toThrow(InvalidStateTransition::class)
                ->and(fn () => app(PaymentLinkStateMachine::class)->markPaid($locked))->toThrow(InvalidStateTransition::class);
        });
    });
});
