<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Jobs\ReconcilePaymentAttemptsJob;
use App\Modules\Payments\Services\PaymentAttemptLookup;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every 15 minutes (routes/console.php, plan 12.5): queues one
 * ReconcilePaymentAttemptsJob per tenant and mode with open attempts or
 * links in `processing`.
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'axispay:payments:reconcile';

    protected $description = 'Re-sync payment attempts that are not final with the gateway and void stale authorizations.';

    public function handle(PaymentAttemptLookup $lookup, TenantContext $context): int
    {
        $scopes = $lookup->scopesToReconcile();

        foreach ($scopes as $scope) {
            $context->runAsTenant($scope['tenant_id'], $scope['livemode'], static function (): void {
                ReconcilePaymentAttemptsJob::dispatch();
            });
        }

        $this->components->info('Queued the reconciliation of '.count($scopes).' tenant mode(s).');

        return self::SUCCESS;
    }
}
