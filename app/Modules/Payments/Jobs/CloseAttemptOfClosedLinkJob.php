<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use App\Modules\Payments\Exceptions\CallBudgetExhausted;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

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

    /** Below the queue's retry_after (150 s): two bounded Stripe calls (42 s each) plus the merchant validation (ADR-0051). */
    public int $timeout = 115;

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
        try {
            // Its gateway calls end before the job's own time limit (ADR-0051).
            $void->handle($this->paymentAttemptId, VoidReason::LinkClosed, budget: CallBudget::forJob($this->timeout));
        } catch (CallBudgetExhausted) {
            Log::warning('No time left in the job to void a closed link\'s payment; tried again later.', ['payment_attempt_id' => $this->paymentAttemptId]);
            $this->release(30);
        } catch (GatewayAuthenticationException|GatewayRequestException $e) {
            $this->fail($e); // plan 12.6: never retried
        } catch (AttemptBusyException) {
            // A confirmation holds the attempt; it hands the attempt back when
            // it ends (ReleaseLinkAfterAttempt). Try again later anyway.
            $this->release(30);
        }
    }
}
