<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Console;

use App\Modules\Gateways\Jobs\CheckApiKeyConnectionsJob;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Scheduled daily (routes/console.php): queues one health check per api_key
 * connection, each in its own tenant context (plan 6.3, 12.3.3).
 */
final class CheckApiKeyConnectionsCommand extends Command
{
    protected $signature = 'axispay:gateways:check-api-keys';

    protected $description = 'Queue the daily health check of every api_key gateway connection.';

    public function handle(GatewayConnectionResolver $resolver, TenantContext $context): int
    {
        $count = 0;

        foreach ($resolver->apiKeyConnectionsToCheck() as $connection) {
            $context->runAsTenant($connection->tenant_id, $connection->livemode, static function () use ($connection): void {
                CheckApiKeyConnectionsJob::dispatch($connection->id);
            });
            $count++;
        }

        $this->components->info("Queued {$count} api_key health check(s).");

        return self::SUCCESS;
    }
}
