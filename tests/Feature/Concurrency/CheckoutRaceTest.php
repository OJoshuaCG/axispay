<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ApiTestHelpers;

/**
 * Plan 26.2 critical case 1: a single-use link opened in several sessions.
 * Eight separate PHP processes (own connections, own transactions) press
 * "Pay" on the same link at the same instant, against the real MariaDB,
 * with the checkout sandbox as gateway. Exactly one attempt exists, exactly
 * one process pays, and every other one is told a payment is in progress
 * (or, if it arrives after, that the link is already paid).
 *
 * No RefreshDatabase: the rows must be committed to be visible to the child
 * processes; the database is rebuilt afterwards (audit_logs is append-only).
 */
beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * @return list<string>
 */
function checkoutRace(string $token, int $processes = 8, int $cancellers = 0): array
{
    $barrier = sys_get_temp_dir().'/axispay-checkout-race-'.bin2hex(random_bytes(6));
    mkdir($barrier);
    $running = [];

    try {
        foreach (range(1, $processes) as $i) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Fixtures/checkout-race.php'), $token, $barrier, $i <= $cancellers ? 'cancel' : 'pay'],
                base_path(),
                ['APP_ENV' => 'testing', 'AXISPAY_CHECKOUT_SANDBOX' => 'true', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'],
                timeout: 60,
            );
            $process->start();
            $running[] = $process;
        }

        $deadline = microtime(true) + 45;

        while (count(glob($barrier.'/ready-*') ?: []) < $processes) {
            expect(microtime(true))->toBeLessThan($deadline, 'Not every race process booted in time.');
            usleep(20_000);
        }

        touch($barrier.'/go');
        $outcomes = [];

        foreach ($running as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
            $outcomes[] = trim($process->getOutput());
        }

        return $outcomes;
    } finally {
        foreach ($running as $process) {
            $process->stop(0);
        }

        array_map(unlink(...), glob($barrier.'/*') ?: []);
        rmdir($barrier);
    }
}

it('lets only one of eight simultaneous sessions pay a link (case 1)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));

    $outcomes = checkoutRace($link->public_token);
    $counts = array_count_values($outcomes);

    $attempts = PaymentAttempt::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->get();
    $fresh = PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id);

    expect($counts['paid'] ?? 0)->toBe(1, implode(',', $outcomes))
        ->and(array_diff(array_keys($counts), ['paid', 'in_progress', 'already_paid']))->toBe([], implode(',', $outcomes))
        ->and(($counts['in_progress'] ?? 0) + ($counts['already_paid'] ?? 0))->toBe(7)
        // A real race: at least one session overlapped the winner.
        ->and($counts['in_progress'] ?? 0)->toBeGreaterThanOrEqual(1, 'No process overlapped the winner: '.implode(',', $outcomes))
        ->and($attempts)->toHaveCount(1)
        ->and($attempts->first()?->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($fresh->status)->toBe(PaymentLinkStatus::Paid);
})->group('concurrency');

it('never pays a link that a simultaneous cancellation canceled, and vice versa (plan 9.1)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));

    $outcomes = checkoutRace($link->public_token, 8, cancellers: 4);
    $counts = array_count_values($outcomes);
    $fresh = PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id);
    $succeeded = PaymentAttempt::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->where('status', PaymentAttemptStatus::Succeeded->value)->count();

    expect(array_diff(array_keys($counts), ['paid', 'in_progress', 'already_paid', 'canceled', 'not_cancelable']))->toBe([], implode(',', $outcomes));

    if ($fresh->status === PaymentLinkStatus::Paid) {
        expect($counts['canceled'] ?? 0)->toBe(0, implode(',', $outcomes))->and($succeeded)->toBe(1)->and($counts['paid'] ?? 0)->toBe(1);
    } else {
        expect($fresh->status)->toBe(PaymentLinkStatus::Canceled)
            ->and($counts['paid'] ?? 0)->toBe(0, implode(',', $outcomes))
            ->and($succeeded)->toBe(0)
            // Canceling a canceled link answers it again (idempotent, plan 10.5).
            ->and($counts['canceled'] ?? 0)->toBeGreaterThanOrEqual(1);
    }
})->group('concurrency');
