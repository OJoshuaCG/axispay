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
 *  - every payment under way at the gateway (3D Secure, authorized,
 *    processing) that has not changed for
 *    `axispay.payments.reconcile_after_minutes` is re-read and applied
 *    through the same actions as the webhooks (missed events);
 *    authorizations past the capture window are voided. Oldest visit first
 *    (`reconciled_at`, set on every visit, failures included), at most
 *    `reconcile_batch_size` rows and `reconcile_time_budget_seconds` per
 *    run, so no group of rows can starve the others;
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

    /** Below the queue's retry_after (150 s): no new sync starts after the 25 s budget, and one sync takes at most about 90 s (ADR-0051). */
    public int $timeout = 115;

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
        // Time box: no new sync starts after the budget (each one is bounded
        // by the Stripe call limits); the next run continues where the cursor is.
        $deadline = microtime(true) + max(1, config()->integer('axispay.payments.reconcile_time_budget_seconds'));

        // Only payments under way at the gateway: attempts still waiting for
        // a card cost nothing while they wait and are closed with their link
        // (expiry, cancellation). Oldest visit first, so failing rows rotate.
        PaymentAttempt::query()
            ->whereIn('status', array_map(static fn (PaymentAttemptStatus $s): string => $s->value, [PaymentAttemptStatus::RequiresAction, PaymentAttemptStatus::RequiresCapture, PaymentAttemptStatus::Processing]))
            ->where('updated_at', '<=', $staleBefore->utc()->format('Y-m-d H:i:s.u'))
            ->whereNotNull('provider_payment_id')
            ->orderByRaw('reconciled_at IS NOT NULL, reconciled_at')
            ->orderBy('id')
            ->limit(max(1, config()->integer('axispay.payments.reconcile_batch_size')))
            ->pluck('id')
            ->each(static function (mixed $attemptId) use ($sync, $deadline): bool {
                if (! is_string($attemptId)) {
                    return true;
                }

                if (microtime(true) >= $deadline) {
                    return false;
                }

                // The visit is recorded first (without touching updated_at, the
                // idle clock), so a row that fails goes to the back of the queue.
                PaymentAttempt::query()->whereKey($attemptId)->toBase()->update(['reconciled_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.u')]);

                try {
                    $sync->handle($attemptId, SyncReason::Reconciliation);
                } catch (Throwable $e) {
                    Log::warning('A payment attempt could not be reconciled.', ['payment_attempt_id' => $attemptId, 'exception' => $e::class]);
                    report($e);
                }

                return true;
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
