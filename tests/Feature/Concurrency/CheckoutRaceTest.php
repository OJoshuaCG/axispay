<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Sandbox\SandboxPaymentGateway;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestHelpers;
use Tests\Support\RaceHarness;

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
function checkoutRace(string $token, int $processes = 8, int $cancellers = 0, int $cancelDelayMs = 0): array
{
    $actors = [];

    foreach (range(1, $processes) as $i) {
        $actors[] = $i <= $cancellers ? ['mode' => 'cancel', 'token' => $token, 'delay_ms' => $cancelDelayMs] : ['mode' => 'pay', 'token' => $token];
    }

    return RaceHarness::run($actors);
}

/** Captures the sandbox performed for a gateway payment (shared database cache). */
function sandboxCaptures(?string $providerPaymentId): int
{
    if ($providerPaymentId === null) {
        return 0;
    }

    $state = Cache::store('database')->get('axispay:sandbox:pi:'.$providerPaymentId);

    return is_array($state) && is_int($state['captures'] ?? null) ? $state['captures'] : 0;
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

it('never pays a link that a simultaneous cancellation canceled, and vice versa, over several rounds (plan 9.1)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $finals = [];

    // Round delays of the cancellers: at once (cancel usually wins) and late (pay wins).
    foreach ([0, 0, 4000, 4000] as $delay) {
        $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));
        $outcomes = checkoutRace($link->public_token, 8, cancellers: 4, cancelDelayMs: $delay);
        $counts = array_count_values($outcomes);
        $fresh = PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id);
        $attempts = PaymentAttempt::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->get();
        $captures = $attempts->sum(static fn (PaymentAttempt $attempt): int => sandboxCaptures($attempt->provider_payment_id));
        $finals[] = $fresh->status;

        expect(array_diff(array_keys($counts), ['paid', 'in_progress', 'already_paid', 'canceled', 'not_cancelable']))->toBe([], implode(',', $outcomes))
            ->and($captures)->toBeLessThanOrEqual(1, implode(',', $outcomes));

        if ($fresh->status === PaymentLinkStatus::Paid) {
            expect($counts['canceled'] ?? 0)->toBe(0, implode(',', $outcomes))
                ->and($attempts->where('status', PaymentAttemptStatus::Succeeded)->count())->toBe(1)
                ->and($counts['paid'] ?? 0)->toBe(1)
                ->and($captures)->toBe(1);
        } else {
            expect($fresh->status)->toBe(PaymentLinkStatus::Canceled)
                ->and($counts['paid'] ?? 0)->toBe(0, implode(',', $outcomes))
                ->and($attempts->whereIn('status', [PaymentAttemptStatus::Succeeded, PaymentAttemptStatus::RequiresCapture])->count())->toBe(0)
                ->and($captures)->toBe(0)
                // Canceling a canceled link answers it again (idempotent, plan 10.5).
                ->and($counts['canceled'] ?? 0)->toBeGreaterThanOrEqual(1);
        }
    }

    expect($finals)->toContain(PaymentLinkStatus::Paid)->toContain(PaymentLinkStatus::Canceled);
})->group('concurrency');

it('claims the first payment of eight links of one tenant at once without deadlocks (M2)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $links = array_map(static fn (): PaymentLink => ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000])), range(1, 8));

    $outcomes = RaceHarness::run(array_map(static fn (PaymentLink $link): array => ['mode' => 'pay', 'token' => $link->public_token], $links));

    expect($outcomes)->toBe(array_fill(0, 8, 'paid'));
})->group('concurrency');

it('captures an authorization at most once while webhooks, the checkout and the reconciliation race (ADR-0051)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->processing()->state(['currency' => 'MXN', 'amount_minor' => 150_000]));

    $attempt = app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($link): PaymentAttempt {
        $connection = GatewayConnection::query()->current()->firstOrFail();
        $sandbox = new SandboxPaymentGateway(app(StripeGateway::class), Cache::store('database'));
        $attempt = PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::RequiresCapture)->createOne([
            'payment_link_id' => $link->id,
            'gateway_connection_id' => $connection->id,
            'provider_payment_id' => null,
            'amount_minor' => 150_000,
            'currency' => 'MXN',
            'original_amount_minor' => 150_000,
            'original_currency' => 'MXN',
            // Old enough for the reconciliation to void it.
            'authorized_at' => now()->subHour(),
        ]);
        $created = $sandbox->createOrUpdatePayment($connection, new PaymentRequest(150_000, 'MXN', 'race', ['axispay_attempt_id' => $attempt->id], 'race-create-'.$attempt->id));
        $sandbox->confirmPayment($connection, $created->providerPaymentId, 'ctoken_sandbox_success_race', 'race-confirm-'.$attempt->id, 'https://example.com');
        $attempt->forceFill(['provider_payment_id' => $created->providerPaymentId])->save();

        return $attempt;
    });

    $actors = [];

    foreach (range(1, 8) as $i) {
        $actors[] = ['mode' => ['webhook', 'reconcile', 'capture'][$i % 3], 'token' => $link->public_token, 'attempt' => $attempt->id];
    }

    $outcomes = RaceHarness::run($actors);
    $fresh = PaymentAttempt::query()->withoutGlobalScopes()->findOrFail($attempt->id);
    $freshLink = PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id);
    $captures = sandboxCaptures($fresh->provider_payment_id);

    expect($captures)->toBeLessThanOrEqual(1, implode(',', $outcomes))
        ->and($fresh->status)->toBeIn([PaymentAttemptStatus::Succeeded, PaymentAttemptStatus::Canceled], implode(',', $outcomes));

    if ($fresh->status === PaymentAttemptStatus::Succeeded) {
        expect($captures)->toBe(1)->and($freshLink->status)->toBe(PaymentLinkStatus::Paid);
    } else {
        expect($captures)->toBe(0)->and($freshLink->status)->toBe(PaymentLinkStatus::Active);
    }
})->group('concurrency');

it('counts only the confirmations of a parallel burst that reached the gateway: the link is not paused (ADR-0051)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));

    // Six real cards declined at the same instant: the lease lets them in one at a time.
    $outcomes = RaceHarness::run(array_fill(0, 6, ['mode' => 'pay', 'token' => $link->public_token, 'scenario' => 'decline']));
    $counts = array_count_values($outcomes);
    $counted = (new RateLimiter(Cache::store('database')))->attempts('checkout:link:'.$link->id);

    expect(array_diff(array_keys($counts), ['declined', 'in_progress', 'turnstile_required']))->toBe([], implode(',', $outcomes))
        ->and($counts['declined'] ?? 0)->toBeGreaterThanOrEqual(1)
        // In progress (and Turnstile) answers never reached the gateway: not counted.
        ->and(is_numeric($counted) ? (int) $counted : -1)->toBe($counts['declined'] ?? 0, implode(',', $outcomes))
        ->and(Cache::store('database')->get('checkout:link-paused:'.$link->id))->toBeNull();
})->group('concurrency');
