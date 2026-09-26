<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Console;

use App\Modules\Gateways\Services\ChargeReadiness;
use App\Modules\PaymentLinks\Jobs\CancelLinksOfDisconnectedGatewayJob;
use App\Modules\PaymentLinks\Services\PaymentLinkLookup;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Scheduled every fifteen minutes (routes/console.php): a safety net for
 * plan 12.3.4. For every tenant and mode that still has active links but no
 * gateway connection that is not disconnected, it queues the cancellation
 * of those links (reason `gateway_disconnected`). A restricted connection
 * keeps its links (plan 12.3.4): only a missing connection counts.
 */
final class ReconcileGatewayLinksCommand extends Command
{
    protected $signature = 'axispay:payment-links:reconcile-gateways';

    protected $description = 'Cancel active payment links of modes whose gateway connection is gone.';

    public function handle(PaymentLinkLookup $lookup, TenantContext $context, ChargeReadiness $readiness): int
    {
        $queued = 0;

        foreach ($lookup->scopesWithActiveLinks() as $scope) {
            $context->runAsTenant($scope['tenant_id'], $scope['livemode'], static function () use ($readiness, &$queued): void {
                if (! $readiness->hasConnectionInCurrentMode()) {
                    CancelLinksOfDisconnectedGatewayJob::dispatch();
                    $queued++;
                }
            });
        }

        $this->components->info("Queued the cancellation of links in {$queued} tenant mode(s) without a gateway connection.");

        return self::SUCCESS;
    }
}
