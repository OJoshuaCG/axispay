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

it('refuses the attempt-driven transitions until Phase 4, even when the table allows them', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->processing()->pastExpiry());

    // processing -> expired is in the plan's table, but payment attempts drive it.
    expect(PaymentLinkStateMachine::canTransition(S::Processing, S::Expired))->toBeTrue();

    app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($link): void {
        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            expect(fn () => app(PaymentLinkStateMachine::class)->expire($locked))
                ->toThrow(InvalidStateTransition::class, 'payment attempts (Phase 4)');
        });
    });

    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(S::Processing);
});
