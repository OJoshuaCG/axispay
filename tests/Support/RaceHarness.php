<?php

declare(strict_types=1);

namespace Tests\Support;

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
 * The children use the checkout sandbox, whose payments live in the shared
 * database cache.
 */
final class RaceHarness
{
    /**
     * @param  list<array{mode: string, token: string, scenario?: string, delay_ms?: int, attempt?: string}>  $actors
     * @return list<string> the outcome printed by each actor, in order
     */
    public static function run(array $actors): array
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
}
