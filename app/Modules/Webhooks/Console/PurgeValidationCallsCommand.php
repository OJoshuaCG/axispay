<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Webhooks\Services\ValidationCallRetention;
use Illuminate\Console\Command;

/**
 * Scheduled daily (routes/console.php): deletes pre-payment validation calls
 * older than their 30-day retention (plan 7.6).
 */
final class PurgeValidationCallsCommand extends Command
{
    protected $signature = 'axispay:validation-calls:purge';

    protected $description = 'Delete pre-payment validation calls past their retention.';

    public function handle(ValidationCallRetention $retention): int
    {
        $this->components->info("Deleted {$retention->purge()} validation call(s).");

        return self::SUCCESS;
    }
}
