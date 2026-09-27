<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Console;

use App\Modules\ProviderEvents\Actions\RecoverProviderEvent;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Services\ProviderEventInbox;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled every five minutes (routes/console.php, ADR-0051): queues again
 * the events stuck in `received` (their job was lost) and routes the
 * unroutable events whose connection exists now. Failed events are left to
 * the operator (`axispay:provider-events:retry --failed`).
 */
final class SweepProviderEventsCommand extends Command
{
    private const int BATCH = 200;

    protected $signature = 'axispay:provider-events:sweep';

    protected $description = 'Queue again gateway events stuck in received and route unroutable events whose connection now exists.';

    public function handle(ProviderEventInbox $inbox, RecoverProviderEvent $recover): int
    {
        $after = max(1, config()->integer('axispay.gateways.stripe.provider_events.redispatch_after_seconds'));
        $counts = [RecoverProviderEvent::REQUEUED => 0, RecoverProviderEvent::REROUTED => 0, RecoverProviderEvent::SKIPPED => 0];
        $events = [
            ...$inbox->staleReceived(CarbonImmutable::now()->subSeconds($after), self::BATCH)->all(),
            ...$inbox->withStatus(ProviderEventStatus::Unroutable, self::BATCH, newestFirst: true)->all(),
        ];

        foreach ($events as $event) {
            try {
                $counts[$recover->handle($event, includeFailed: false)]++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->components->info("Requeued {$counts['requeued']} event(s); routed {$counts['rerouted']} unroutable event(s).");

        return self::SUCCESS;
    }
}
