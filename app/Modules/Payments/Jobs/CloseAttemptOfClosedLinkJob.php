<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Plan 9.1: a link that expired or was canceled cancels its waiting gateway
 * payment. Runs after the link's transaction committed, with retries (the
 * same idempotency key every time). If the gateway says the payment had
 * already succeeded, the payment wins (ApplyProviderPayment).
 */
final class CloseAttemptOfClosedLinkJob implements ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $paymentAttemptId)
    {
        $this->captureTenantContext();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(VoidAuthorization $void): void
    {
        $void->handle($this->paymentAttemptId, 'link_closed');
    }
}
