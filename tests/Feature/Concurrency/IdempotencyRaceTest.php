<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Models\IdempotencyRecord;
use App\Modules\ApiKeys\Services\RequestFingerprint;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ApiTestHelpers;

/**
 * Plan 10.3 / 27 Phase 3 acceptance: concurrent requests with the same
 * Idempotency-Key. Eight separate PHP processes (own connections, own
 * transactions) run claim → create link → store answer at the same instant
 * (start barrier) against the real MariaDB. The children call the store and
 * the action directly; the HTTP middleware around them is covered
 * in-process by Feature/ApiKeys/IdempotencyTest. Exactly one creates the link; the others get
 * `idempotency_request_in_progress` or, once the winner stored its answer,
 * a replay.
 *
 * No RefreshDatabase here: the rows must be committed to be visible to the
 * child processes. audit_logs is append-only (its rows cannot be deleted), so
 * the database is rebuilt afterwards.
 */

/**
 * Runs `$processes` children that claim the key at the same moment (start
 * barrier) and returns their outcomes.
 *
 * @return list<string>
 */
function raceOutcomes(string $tenantId, string $apiKeyId, string $key, int $processes = 8): array
{
    $barrier = sys_get_temp_dir().'/axispay-race-'.bin2hex(random_bytes(6));
    mkdir($barrier);
    $running = [];

    try {
        foreach (range(1, $processes) as $i) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Fixtures/idempotency-race.php'), $tenantId, $apiKeyId, $key, $barrier],
                base_path(),
                ['APP_ENV' => 'testing'],
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

/**
 * The outcomes must show a real race: exactly one winner and at least one
 * request that found the key still in progress (not only late replays).
 *
 * @param  list<string>  $outcomes
 */
function assertRaced(array $outcomes): void
{
    $counts = array_count_values($outcomes);

    expect($counts['proceed'] ?? 0)->toBe(1, implode(',', $outcomes))
        ->and($counts['idempotency_request_in_progress'] ?? 0)->toBeGreaterThanOrEqual(1, 'No process overlapped the winner: '.implode(',', $outcomes))
        ->and(array_diff(array_keys($counts), ['proceed', 'replay', 'idempotency_request_in_progress']))->toBe([]);
}

beforeEach(function (): void {
    // These tests rebuild the database: never anything but the test database.
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('creates exactly one link when eight processes race with the same key', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);

    assertRaced(raceOutcomes($tenant->id, $apiKey->id, 'race-key'));

    expect(PaymentLink::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('action', AuditAction::PaymentLinkCreated->value)->count())->toBe(1)
        ->and(IdempotencyRecord::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->response_status)->toBe(201);
})->group('concurrency');

it('lets exactly one process take over a key whose owner died', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);

    // A claim left behind by a crashed request: no answer, lock expired.
    app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($apiKey): void {
        (new IdempotencyRecord)->forceFill([
            'api_key_id' => $apiKey->id,
            'idempotency_key' => 'orphan-key',
            'request_method' => 'POST',
            'request_path' => '/v1/payment_links',
            'request_hash' => app(RequestFingerprint::class)->of('{"amount":"150.00","currency":"MXN","description":"Race order"}'),
            'locked_until' => now()->subMinute(),
            'lock_token' => str_repeat('0', 32),
            'expires_at' => now()->addDay(),
        ])->save();
    });

    assertRaced(raceOutcomes($tenant->id, $apiKey->id, 'orphan-key'));
    expect(PaymentLink::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(ApiKey::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
})->group('concurrency');
