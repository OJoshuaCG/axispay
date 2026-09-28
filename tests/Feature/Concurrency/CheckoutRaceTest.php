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
use Carbon\CarbonImmutable;
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

/** Capture calls the platform made to the sandbox for a gateway payment (counted atomically, shared database cache). */
function sandboxCaptures(?string $providerPaymentId): int
{
    return $providerPaymentId === null ? 0 : (new SandboxPaymentGateway(app(StripeGateway::class), Cache::store('database')))->captureCallsOf($providerPaymentId);
}

/**
 * A payment link with an authorized (not captured) sandbox payment, as a
 * checkout leaves it when the capture did not happen yet.
 */
function raceAuthorizedAttempt(PaymentLink $link, CarbonImmutable $authorizedAt): PaymentAttempt
{
    return app(TenantContext::class)->runAsTenant($link->tenant_id, false, static function () use ($link, $authorizedAt): PaymentAttempt {
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
            'authorized_at' => $authorizedAt,
        ]);
        $created = $sandbox->createOrUpdatePayment($connection, new PaymentRequest(150_000, 'MXN', 'race', ['axispay_attempt_id' => $attempt->id], 'race-create-'.$attempt->id));
        $sandbox->confirmPayment($connection, $created->providerPaymentId, 'ctoken_sandbox_success_race', 'race-confirm-'.$attempt->id, 'https://example.com');
        $attempt->forceFill(['provider_payment_id' => $created->providerPaymentId])->save();

        return $attempt;
    });
}

/**
 * @return list<array{mode: string, token: string, attempt: string}>
 */
function raceCompleters(PaymentLink $link, PaymentAttempt $attempt): array
{
    return array_map(static fn (int $i): array => ['mode' => ['webhook', 'reconcile', 'capture'][$i % 3], 'token' => $link->public_token, 'attempt' => $attempt->id], range(1, 8));
}

it('lets only one of eight simultaneous sessions pay a link (case 1)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));

    // The winner holds inside the gateway until every other session answered:
    // they are guaranteed to overlap it.
    $outcomes = RaceHarness::run(array_fill(0, 8, ['mode' => 'pay', 'token' => $link->public_token, 'hold' => true]), static function (array $processes, string $dir): void {
        RaceHarness::whenHolding($dir);
        $holder = (int) substr(basename((glob($dir.'/holding-*') ?: [''])[0]), strlen('holding-'));
        RaceHarness::whenFinished(array_values(array_filter($processes, static fn ($process): bool => $process->getPid() !== $holder)));
        RaceHarness::signal($dir, 'release');
    });
    $counts = array_count_values($outcomes);

    $attempts = PaymentAttempt::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->get();
    $fresh = PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id);

    expect($counts['paid'] ?? 0)->toBe(1, implode(',', $outcomes))
        ->and(array_diff(array_keys($counts), ['paid', 'in_progress', 'already_paid']))->toBe([], implode(',', $outcomes))
        // Every other session overlapped the winner.
        ->and($counts['in_progress'] ?? 0)->toBe(7, implode(',', $outcomes))
        ->and($attempts)->toHaveCount(1)
        ->and($attempts->first()?->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($fresh->status)->toBe(PaymentLinkStatus::Paid);
})->group('concurrency');

it('never pays a link canceled first: payers arriving after the cancellation are refused (plan 9.1)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));
    $actors = [
        ...array_fill(0, 4, ['mode' => 'cancel', 'token' => $link->public_token]),
        ...array_fill(0, 4, ['mode' => 'pay', 'token' => $link->public_token, 'wait' => 'pay-go']),
    ];

    // The payers start only once every cancellation answered.
    $outcomes = RaceHarness::run($actors, static function (array $processes, string $dir): void {
        RaceHarness::whenFinished(array_slice($processes, 0, 4));
        RaceHarness::signal($dir, 'pay-go');
    });
    $attempts = PaymentAttempt::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->get();

    expect(array_slice($outcomes, 0, 4))->toBe(array_fill(0, 4, 'canceled'), implode(',', $outcomes))
        ->and(array_slice($outcomes, 4))->toBe(array_fill(0, 4, 'canceled'), implode(',', $outcomes))
        ->and(PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id)->status)->toBe(PaymentLinkStatus::Canceled)
        ->and($attempts)->toHaveCount(0);
})->group('concurrency');

it('never cancels a link while its payment is under way: the payment completes once (plan 9.1)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));
    $actors = [
        ...array_fill(0, 4, ['mode' => 'pay', 'token' => $link->public_token, 'hold' => true]),
        ...array_fill(0, 4, ['mode' => 'cancel', 'token' => $link->public_token, 'wait' => 'cancel-go']),
    ];

    // The cancellations run while the winning payment holds inside the gateway.
    $outcomes = RaceHarness::run($actors, static function (array $processes, string $dir): void {
        RaceHarness::whenHolding($dir);
        RaceHarness::signal($dir, 'cancel-go');
        RaceHarness::whenFinished(array_slice($processes, 4));
        RaceHarness::signal($dir, 'release');
    });
    $counts = array_count_values(array_slice($outcomes, 0, 4));
    $attempts = PaymentAttempt::query()->withoutGlobalScopes()->where('payment_link_id', $link->id)->get();

    expect(array_slice($outcomes, 4))->toBe(array_fill(0, 4, 'not_cancelable'), implode(',', $outcomes))
        ->and($counts['paid'] ?? 0)->toBe(1, implode(',', $outcomes))
        ->and($counts['in_progress'] ?? 0)->toBe(3, implode(',', $outcomes))
        ->and(PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id)->status)->toBe(PaymentLinkStatus::Paid)
        ->and($attempts->where('status', PaymentAttemptStatus::Succeeded)->count())->toBe(1)
        ->and(sandboxCaptures($attempts->first()?->provider_payment_id))->toBe(1);
})->group('concurrency');

it('claims the first payment of eight links of one tenant at once without deadlocks (M2)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $links = array_map(static fn (): PaymentLink => ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000])), range(1, 8));

    $outcomes = RaceHarness::run(array_map(static fn (PaymentLink $link): array => ['mode' => 'pay', 'token' => $link->public_token], $links));

    expect($outcomes)->toBe(array_fill(0, 8, 'paid'));
})->group('concurrency');

it('voids an authorization past its capture window once while webhooks, the checkout and the reconciliation race (ADR-0051)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->processing()->state(['currency' => 'MXN', 'amount_minor' => 150_000]));
    // Authorized an hour ago: past the capture window, so everybody voids.
    $attempt = raceAuthorizedAttempt($link, CarbonImmutable::now()->subHour());

    $outcomes = RaceHarness::run(raceCompleters($link, $attempt));
    $fresh = PaymentAttempt::query()->withoutGlobalScopes()->findOrFail($attempt->id);

    expect($fresh->status)->toBe(PaymentAttemptStatus::Canceled, implode(',', $outcomes))
        ->and(sandboxCaptures($fresh->provider_payment_id))->toBe(0)
        ->and(PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id)->status)->toBe(PaymentLinkStatus::Active);
})->group('concurrency');

it('captures an authorization with exactly one gateway call while webhooks, the checkout and the reconciliation race (ADR-0051)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->processing()->state(['currency' => 'MXN', 'amount_minor' => 150_000]));
    // Authorized just now: within the capture window, so everybody tries to capture.
    $attempt = raceAuthorizedAttempt($link, CarbonImmutable::now());

    $outcomes = RaceHarness::run(raceCompleters($link, $attempt));
    $fresh = PaymentAttempt::query()->withoutGlobalScopes()->findOrFail($attempt->id);

    expect($fresh->status)->toBe(PaymentAttemptStatus::Succeeded, implode(',', $outcomes))
        ->and(sandboxCaptures($fresh->provider_payment_id))->toBe(1, implode(',', $outcomes))
        ->and(PaymentLink::query()->withoutGlobalScopes()->findOrFail($link->id)->status)->toBe(PaymentLinkStatus::Paid);
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
