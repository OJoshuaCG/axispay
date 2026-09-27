<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Jobs;

use App\Modules\Audit\Data\Actor;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Plan 21.3: cancels every active link of a closed tenant in the current
 * mode with reason `tenant_closed` (system actor), through the link state
 * machine. Idempotent (only active links), retried by the queue. A link with
 * a payment under way (`processing`) is left to finish; the job runs again
 * later for it, and meanwhile the checkout refuses new payments of a closed
 * tenant. Nothing happens if the tenant was reopened before the job ran.
 */
final class CancelLinksOfClosedTenantJob implements ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public int $timeout = 55;

    public function __construct()
    {
        $this->captureTenantContext();
        $this->afterCommit();
    }

    public function handle(CancelPaymentLink $cancel): void
    {
        if (Tenant::query()->find($this->capturedTenantId)?->status !== TenantStatus::Closed) {
            return;
        }

        PaymentLink::query()
            ->where('status', PaymentLinkStatus::Active->value)
            ->eachById(static function (PaymentLink $link) use ($cancel): void {
                try {
                    $cancel->handle($link, CancelPaymentLinkData::because(CancelReason::TenantClosed), Actor::system());
                } catch (LinkNotCancelableException) {
                    // Expired or a payment started meanwhile: handled below or already final.
                }
            }, max(1, config()->integer('axispay.links.disconnect_cancel_chunk_size')));

        if (PaymentLink::query()->where('status', PaymentLinkStatus::Processing->value)->exists()) {
            $this->release(600); // payments under way: come back for their links
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }
}
