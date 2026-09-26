<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Console;

use App\Modules\ApiKeys\Services\IdempotencyRecordRetention;
use Illuminate\Console\Command;

/**
 * Scheduled hourly (routes/console.php): deletes idempotency records older
 * than their 24-hour lifetime (plan 7.8).
 */
final class PurgeIdempotencyRecordsCommand extends Command
{
    protected $signature = 'axispay:idempotency:purge';

    protected $description = 'Delete expired API idempotency records.';

    public function handle(IdempotencyRecordRetention $retention): int
    {
        $this->components->info("Deleted {$retention->purge()} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
