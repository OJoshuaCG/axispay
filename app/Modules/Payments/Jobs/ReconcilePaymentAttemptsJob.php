<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciliation of one tenant and mode (plan 12.5, ADR-0050), queued every
 * 15 minutes by `axispay:payments:reconcile`:
 *
 *  - every attempt that is not final and has not changed for
 *    `axispay.payments.reconcile_after_minutes` is re-read from the gateway
 *    and applied through the same actions as the webhooks (missed events);
 *    authorizations left uncaptured too long are voided;
 *  - a link stuck in `processing` without any attempt under way (an
 *    interrupted flow) is made payable again, or expired.
 *
 * One attempt failing is logged and skipped; the next run tries it again.
 * Unique per tenant and mode while queued or running.
 */
final class ReconcilePaymentAttemptsJob implements ShouldBeUnique, ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const int BATCH = 200;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->captureTenantContext();
    }

    public function uniqueId(): string
    {
        return $this->capturedTenantId.':'.($this->capturedLivemode ? 'live' : 'test');
    }

    public function handle(SyncPaymentAttempt $sync, PaymentLinkStateMachine $links): void
    {
        $staleBefore = CarbonImmutable::now()->subMinutes(config()->integer('axispay.payments.reconcile_after_minutes'));

        PaymentAttempt::query()
            ->whereIn('status', PaymentAttemptStatus::activeValues())
            ->where('updated_at', '<=', $staleBefore->utc()->format('Y-m-d H:i:s.u'))
            ->whereNotNull('provider_payment_id')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id')
            ->each(static function (mixed $attemptId) use ($sync): void {
                if (! is_string($attemptId)) {
                    return;
                }

                try {
                    $sync->handle($attemptId, SyncReason::Reconciliation);
                } catch (Throwable $e) {
                    Log::warning('A payment attempt could not be reconciled.', ['payment_attempt_id' => $attemptId, 'exception' => $e::class]);
                    report($e);
                }
            });

        $this->releaseStuckLinks($links, $staleBefore);
    }

    private function releaseStuckLinks(PaymentLinkStateMachine $links, CarbonImmutable $staleBefore): void
    {
        PaymentLink::query()
            ->where('status', PaymentLinkStatus::Processing->value)
            ->where('updated_at', '<=', $staleBefore->utc()->format('Y-m-d H:i:s.u'))
            ->limit(self::BATCH)
            ->pluck('id')
            ->each(static function (mixed $linkId) use ($links): void {
                if (! is_string($linkId)) {
                    return;
                }

                DB::transaction(static function () use ($linkId, $links): void {
                    $locked = PaymentLink::query()->lockForUpdate()->find($linkId);

                    if ($locked === null || $locked->status !== PaymentLinkStatus::Processing) {
                        return;
                    }

                    $inFlight = PaymentAttempt::query()
                        ->where('payment_link_id', $linkId)
                        ->whereIn('status', array_map(static fn (PaymentAttemptStatus $s): string => $s->value, [PaymentAttemptStatus::RequiresAction, PaymentAttemptStatus::RequiresCapture, PaymentAttemptStatus::Processing]))
                        ->exists();

                    if (! $inFlight) {
                        $links->resumeAfterAttempt($locked);
                        Log::notice('A payment link stuck in processing was released.', ['payment_link_id' => $linkId, 'status' => $locked->status->value]);
                    }
                });
            });
    }
}
