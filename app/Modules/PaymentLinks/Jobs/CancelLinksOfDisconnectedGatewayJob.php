<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Jobs;

use App\Modules\Audit\Data\Actor;
use App\Modules\Gateways\Services\ChargeReadiness;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Plan 12.3.4: cancels every active link of the current tenant and mode
 * with reason `gateway_disconnected` (system actor). Queued after the
 * disconnection committed, in the tenant context it was dispatched from.
 *
 * Idempotent: it only touches links that are still active, so a retry after
 * a failure halfway (deadlock, timeout, lost worker) finishes the rest.
 * Links with a payment in progress are left to finish. If a connection that
 * is not disconnected exists again in this mode when the job runs (the
 * tenant reconnected meanwhile), nothing is canceled.
 */
final class CancelLinksOfDisconnectedGatewayJob implements ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public int $timeout = 55;

    public function __construct()
    {
        $this->captureTenantContext();
        $this->afterCommit();
    }

    public function handle(CancelPaymentLink $cancel, ChargeReadiness $readiness): void
    {
        if ($readiness->hasConnectionInCurrentMode()) {
            return;
        }

        // Keyset paging by ID: canceled rows leave the filter, so offset
        // paging would skip links beyond the first chunk.
        PaymentLink::query()
            ->where('status', PaymentLinkStatus::Active->value)
            ->eachById(static function (PaymentLink $link) use ($cancel): void {
                try {
                    $cancel->handle($link, CancelPaymentLinkData::because(CancelReason::GatewayDisconnected), Actor::system());
                } catch (LinkNotCancelableException) {
                    // Expired or changed state meanwhile: nothing to cancel.
                }
            }, max(1, config()->integer('axispay.links.disconnect_cancel_chunk_size')));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }
}
