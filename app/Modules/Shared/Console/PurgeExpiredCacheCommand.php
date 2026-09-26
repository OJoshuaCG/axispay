<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled hourly (routes/console.php): deletes expired rows (values and
 * locks) of the database cache store, which Laravel only removes when a key is read again
 * (short-lived keys such as the per-IP failed-authentication counters are
 * often never read again). Chunks of 1000 rows. Does nothing when the default
 * cache store is not the database. Works on the tables of Laravel's cache
 * migration (`cache`, `cache_locks`).
 */
final class PurgeExpiredCacheCommand extends Command
{
    private const int CHUNK = 1000;

    protected $signature = 'axispay:cache:purge-expired';

    protected $description = 'Delete expired rows of the database cache store.';

    public function handle(): int
    {
        $store = config()->string('cache.default');

        if (config("cache.stores.{$store}.driver") !== 'database') {
            $this->components->info('The default cache store is not the database: nothing to purge.');

            return self::SUCCESS;
        }

        $connection = config("cache.stores.{$store}.connection");
        $db = DB::connection(is_string($connection) ? $connection : null);
        $now = now()->getTimestamp();

        // The tables created by the cache migration: values and locks both
        // keep an `expiration` timestamp.
        $total = $this->purge(fn (): int => $db->table('cache')->where('expiration', '<=', $now)->limit(self::CHUNK)->delete())
            + $this->purge(fn (): int => $db->table('cache_locks')->where('expiration', '<=', $now)->limit(self::CHUNK)->delete());

        $this->components->info("Deleted {$total} expired cache row(s).");

        return self::SUCCESS;
    }

    /**
     * @param  callable(): int  $deleteChunk
     */
    private function purge(callable $deleteChunk): int
    {
        $total = 0;

        do {
            $deleted = $deleteChunk();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
