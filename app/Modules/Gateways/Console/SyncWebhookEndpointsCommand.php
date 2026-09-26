<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Console;

use App\Modules\Gateways\Actions\SyncDirectWebhookEndpoint;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Run after changing `axispay.gateways.stripe.direct_webhook_events` (for
 * example when Phase 4 adds the payment events): updates the webhook
 * endpoint of every live api_key connection, each in its tenant context.
 * Safe to run again; failures are listed and do not stop the others.
 */
final class SyncWebhookEndpointsCommand extends Command
{
    protected $signature = 'axispay:stripe-sync-webhook-endpoints';

    protected $description = 'Update the events of the webhook endpoints created on merchant Stripe accounts (api_key) to the configured list.';

    public function handle(GatewayConnectionResolver $resolver, TenantContext $context, SyncDirectWebhookEndpoint $sync): int
    {
        $operationId = Ulid::generate();
        [$updated, $failed] = [0, 0];

        foreach ($resolver->apiKeyConnectionsToCheck() as $row) {
            $context->runAsTenant($row->tenant_id, $row->livemode, function () use ($row, $sync, $operationId, &$updated, &$failed): void {
                $connection = GatewayConnection::query()->find($row->id);

                if ($connection === null) {
                    return;
                }

                try {
                    $updated += $sync->handle($connection, $operationId) ? 1 : 0;
                } catch (GatewayException $e) {
                    $failed++;
                    $this->components->warn("Connection {$connection->id}: ".$e->getMessage());
                }
            });
        }

        $this->components->info("Updated {$updated} endpoint(s); {$failed} failure(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
