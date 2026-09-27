<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One more try at completing an authorized attempt that another process was
 * holding when its webhook arrived. If it is still held, the reconciliation
 * takes over. Only the attempt ID is serialized.
 */
final class CompleteAuthorizedPaymentJob implements ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $paymentAttemptId)
    {
        $this->captureTenantContext();
        $this->onQueue('critical');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CaptureAuthorizedPayment $capture): void
    {
        $capture->handle($this->paymentAttemptId);
    }
}
