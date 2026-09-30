<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use App\Modules\Webhooks\Services\WebhookOutbox;
use App\Modules\Webhooks\Services\WebhookOutboxLookup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The outbox sweeper (plan 15.4 step 3), every minute (routes/console.php):
 *
 *  - publishes domain events that were never published (recorded before
 *    Phase 5, or a publication that failed);
 *  - queues the pending deliveries that are due and have no job: retries
 *    scheduled too far ahead to be queued with a delay, and jobs lost
 *    between the commit and the queue.
 *
 * Every row is handled in its own tenant context; one failure never stops
 * the rest.
 */
final class SweepWebhookOutboxCommand extends Command
{
    protected $signature = 'axispay:webhooks:sweep';

    protected $description = 'Publish unpublished domain events and queue due webhook deliveries.';

    public function handle(WebhookOutboxLookup $lookup, WebhookOutbox $outbox, WebhookDispatcher $dispatcher, TenantContext $context): int
    {
        $now = CarbonImmutable::now();
        $batch = max(1, config()->integer('axispay.webhooks.sweep_batch_size'));
        $stale = $now->subSeconds(max(1, config()->integer('axispay.webhooks.redispatch_after_seconds')));
        [$published, $queued] = [0, 0];

        foreach ($lookup->unpublishedEvents($stale, $batch) as $row) {
            try {
                $published += $context->runAsTenant($row['tenant_id'], $row['livemode'], static fn (): int => DB::transaction(static function () use ($row, $outbox): int {
                    $event = DomainEvent::query()->lockForUpdate()->find($row['id']);

                    if ($event === null || $event->published_at !== null) {
                        return 0;
                    }

                    $outbox->publish($event);

                    return 1;
                }));
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach ($lookup->dueDeliveries($now, $stale, $batch) as $row) {
            try {
                $dispatcher->dispatch($row['id'], $row['tenant_id'], $row['livemode']);
                $queued++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->components->info("Published {$published} event(s); queued {$queued} delivery(ies).");

        return self::SUCCESS;
    }
}
