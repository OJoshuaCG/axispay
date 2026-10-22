<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Webhooks\Services\WebhookDeliveryRetention;
use Illuminate\Console\Command;

/**
 * Scheduled daily (routes/console.php): deletes webhook delivery attempts
 * older than their 30-day retention (plan 15.6, ADR-0067).
 */
final class PurgeWebhookDeliveriesCommand extends Command
{
    protected $signature = 'axispay:webhook-deliveries:purge';

    protected $description = 'Delete webhook delivery attempts past their retention.';

    public function handle(WebhookDeliveryRetention $retention): int
    {
        $this->components->info("Deleted {$retention->purge()} webhook delivery attempt(s).");

        return self::SUCCESS;
    }
}
