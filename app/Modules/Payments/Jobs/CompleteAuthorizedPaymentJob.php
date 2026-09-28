<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One more try at completing an authorized attempt that another process was
 * holding when its webhook arrived. If it is still held, the reconciliation
 * takes over. Only the attempt ID is serialized.
 */
final class CompleteAuthorizedPaymentJob implements ShouldBeUnique, ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Below the queue's retry_after (150 s): two bounded Stripe calls (42 s each) plus the merchant validation (ADR-0051). */
    public int $timeout = 115;

    public int $tries = 3;

    /**
     * One queued completion per attempt: covers the dispatch delay (60 s),
     * every try's time limit and the backoffs (60 + 3 × 115 + 30 + 120 =
     * 555 s), with a margin. Further dispatches while one is pending are
     * dropped; the pending one completes the attempt.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly string $paymentAttemptId)
    {
        $this->captureTenantContext();
        $this->onQueue('critical');
    }

    public function uniqueId(): string
    {
        return $this->paymentAttemptId;
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
        try {
            // Its gateway calls end before the job's own time limit (ADR-0051).
            $capture->handle($this->paymentAttemptId, budget: CallBudget::forJob($this->timeout));
        } catch (GatewayAuthenticationException|GatewayRequestException $e) {
            $this->fail($e); // plan 12.6: never retried
        }
    }
}
