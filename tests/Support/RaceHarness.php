<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Symfony\Component\Process\Process;

/**
 * Runs separate PHP processes (tests/Fixtures/checkout-race.php) against the
 * real MariaDB at the same instant (a shared start barrier), so the races
 * happen in the database, not in PHP. Each actor is described with named
 * arguments, handed to the child as JSON:
 *
 *   ['mode' => 'pay', 'token' => ..., 'scenario' => 'decline']
 *   ['mode' => 'cancel', 'token' => ..., 'delay_ms' => 4000]
 *   ['mode' => 'webhook'|'reconcile'|'capture', 'token' => ..., 'attempt' => ...]
 *
 * Ordering, instead of timing: an actor with `wait` starts only once the
 * file `<run dir>/<wait>` exists; a payer with `hold` => true holds inside
 * its confirmation (HoldingGateway) until `<run dir>/release` exists. The
 * `$whileRunning` callback steers the run with those files (see
 * whenFinished(), whenHolding(), signal()).
 *
 * The children use the checkout sandbox, whose payments live in the shared
 * database cache.
 */
final class RaceHarness
{
    /**
     * @param  list<array{mode: string, token: string, scenario?: string, delay_ms?: int, attempt?: string, wait?: string, hold?: bool}>  $actors
     * @param  (Closure(list<Process>, string): void)|null  $whileRunning  called after the start, with the processes and the run directory
     * @return list<string> the outcome printed by each actor, in order
     */
    public static function run(array $actors, ?Closure $whileRunning = null): array
    {
        $barrier = sys_get_temp_dir().'/axispay-checkout-race-'.bin2hex(random_bytes(6));
        mkdir($barrier);
        $running = [];

        try {
            foreach ($actors as $actor) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Fixtures/checkout-race.php'), $barrier, (string) json_encode($actor)],
                    base_path(),
                    ['APP_ENV' => 'testing', 'AXISPAY_CHECKOUT_SANDBOX' => 'true', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'sync'],
                    timeout: 90,
                );
                $process->start();
                $running[] = $process;
            }

            $deadline = microtime(true) + 60;

            while (count(glob($barrier.'/ready-*') ?: []) < count($actors)) {
                expect(microtime(true))->toBeLessThan($deadline, 'Not every race process booted in time.');
                usleep(20_000);
            }

            touch($barrier.'/go');

            if ($whileRunning !== null) {
                $whileRunning($running, $barrier);
            }

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
     * Waits (at most 60 s) until the given processes have ended.
     *
     * @param  list<Process>  $processes
     */
    public static function whenFinished(array $processes): void
    {
        self::until(static fn (): bool => array_filter($processes, static fn (Process $process): bool => $process->isRunning()) === [], 'The race processes did not finish in time.');
    }

    /** Waits (at most 60 s) until a payer holds inside its confirmation. */
    public static function whenHolding(string $dir): void
    {
        self::until(static fn (): bool => (glob($dir.'/holding-*') ?: []) !== [], 'No payer reached the gateway in time.');
    }

    /** Creates a signal file of the run (`release`, or an actor's `wait`). */
    public static function signal(string $dir, string $name): void
    {
        touch($dir.'/'.$name);
    }

    private static function until(Closure $condition, string $message): void
    {
        $deadline = microtime(true) + 60;

        while (! $condition()) {
            expect(microtime(true))->toBeLessThan($deadline, $message);
            usleep(10_000);
        }
    }
}
