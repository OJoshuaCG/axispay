<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\ValidationTestHelpers as Validation;

/**
 * Plan 26.2 critical case 24 (rules.md rule 7b): the pre-payment validation
 * call holds no database lock, and the state is re-verified after it.
 *
 * The merchant's server is "slow": while the call is in flight (the fake
 * answer runs mid-call), a second connection locks the link and its attempts
 * with NOWAIT, which fails at once if the checkout held any of those rows,
 * and then closes the tenant and commits. The merchant approves, yet the
 * checkout re-locks, sees the closed tenant and voids instead of capturing.
 *
 * No RefreshDatabase: the second connection must see committed rows.
 */
beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
    Artisan::call('migrate:fresh', ['--force' => true]);
    config(['database.connections.mariadb_other' => config('database.connections.mariadb')]);
});

afterEach(function (): void {
    DB::purge('mariadb_other');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('holds no lock during the validation call and re-verifies the state after it (case 24)', function (): void {
    // Filled while the merchant call is in flight (captured by reference).
    $linkId = '';
    $tenantId = '';
    $transactionOpen = null;
    $lockedRows = 0;
    $lockError = null;

    Http::fake(['*' => static function () use (&$linkId, &$tenantId, &$transactionOpen, &$lockedRows, &$lockError) {
        $transactionOpen = Transactions::open() || DB::connection()->transactionLevel() > 0;

        try {
            DB::connection('mariadb_other')->transaction(static function () use ($linkId, &$lockedRows): void {
                $link = PaymentLink::on('mariadb_other')->withoutGlobalScopes()->whereKey($linkId)->lock('for update nowait')->firstOrFail();
                $attempts = PaymentAttempt::on('mariadb_other')->withoutGlobalScopes()->where('payment_link_id', $link->id)->lock('for update nowait')->get();
                $lockedRows = 1 + $attempts->count();
            });

            // Another operation while the merchant is still answering.
            Tenant::on('mariadb_other')->whereKey($tenantId)->update(['status' => TenantStatus::Closed->value]);
        } catch (Throwable $e) {
            $lockError = $e->getMessage();
        }

        return Validation::answer(['decision' => 'approve']);
    }]);

    [$tenant, $link, $fake] = Validation::scenario();
    $linkId = $link->id;
    $tenantId = $tenant->id;

    Checkout::pay($link);

    [$attempt] = Checkout::attempts($link);
    expect($lockError)->toBeNull()
        ->and($transactionOpen)->toBeFalse()
        // The link and its attempt were locked by the other connection, without waiting.
        ->and($lockedRows)->toBe(2)
        // Approved by the merchant, but the tenant closed during the call: voided, never captured.
        ->and($fake->callsTo('capturePayment'))->toBe([])
        ->and($fake->callsTo('cancelPayment'))->not->toBe([])
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Canceled);
});
