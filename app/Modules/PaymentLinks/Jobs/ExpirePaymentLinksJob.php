<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Jobs;

use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkLookup;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled every minute (routes/console.php, plan 9.1): expires active
 * links whose `expires_at` has passed, in batches. A platform-level job: it
 * finds due links through PaymentLinkLookup (identifiers only) and expires
 * each one inside that link's own tenant context (plan 6.3), so it is not
 * TenantAware. Pages by `(expires_at, id)` after the last row seen, so a
 * link that fails is logged and skipped without blocking later ones. Each
 * run stops starting batches after a time budget (40 s by default) so it
 * ends before the worker timeout; the next minute continues. Unique while
 * queued or running, so runs never pile up.
 */
final class ExpirePaymentLinksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Batches per run at most; the rest waits for the next minute. */
    private const int MAX_BATCHES = 10;

    /** Below the worker's 60-second timeout (QUEUE_TIMEOUT). */
    public int $timeout = 55;

    /** The run is unique for about one schedule period. */
    public int $uniqueFor = 60;

    public function handle(PaymentLinkLookup $lookup, TenantContext $context, ExpirePaymentLink $expire): void
    {
        $batchSize = max(1, config()->integer('axispay.links.expire_batch_size'));
        $dueAt = CarbonImmutable::now();
        // Time box: stop starting batches after the budget; the next run
        // (one minute later) continues where due links remain.
        $deadline = microtime(true) + max(0, config()->integer('axispay.links.expire_time_budget_seconds'));
        [$afterExpiresAt, $afterId] = [null, null];

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            if ($batch > 0 && microtime(true) >= $deadline) {
                return;
            }

            $due = $lookup->dueForExpiration($batchSize, $dueAt, $afterExpiresAt, $afterId);

            foreach ($due as $link) {
                try {
                    $context->runAsTenant($link->tenant_id, $link->livemode, static fn (): bool => $expire->handle($link->id));
                } catch (Throwable $e) {
                    // Logged and skipped; the next run tries it again.
                    Log::warning('A payment link could not be expired.', ['payment_link_id' => $link->id, 'exception' => $e::class]);
                    report($e);
                }

                [$afterExpiresAt, $afterId] = [$link->expires_at, $link->id];
            }

            if ($due->count() < $batchSize) {
                return;
            }
        }
    }
}
