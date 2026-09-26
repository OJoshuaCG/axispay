<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Console;

use App\Modules\ProviderEvents\Services\ProviderEventRetention;
use Illuminate\Console\Command;

/**
 * Scheduled daily (routes/console.php): retention of incoming gateway events
 * (plan 14.4). See ProviderEventRetention.
 */
final class PurgeProviderEventsCommand extends Command
{
    protected $signature = 'axispay:provider-events:purge';

    protected $description = 'Delete old ignored/unroutable gateway events and reduce the payload of old processed ones.';

    public function handle(ProviderEventRetention $retention): int
    {
        $result = $retention->purge();

        $this->components->info("Deleted {$result['deleted']} event(s); reduced the payload of {$result['reduced']} event(s).");

        return self::SUCCESS;
    }
}
