<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Console;

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\ProviderEvents\Actions\RecoverProviderEvent;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\ProviderEvents\Services\ProviderEventInbox;
use Illuminate\Console\Command;

/**
 * Operator retry of stored gateway events (ADR-0051): one event (our ID or
 * the gateway's event ID) or every failed event. Idempotent and audited
 * (RecoverProviderEvent); an event already processed is left alone.
 */
final class RetryProviderEventsCommand extends Command
{
    private const int BATCH = 500;

    protected $signature = 'axispay:provider-events:retry
        {id? : Our event ID, or the gateway event ID (evt_...)}
        {--failed : Retry every failed event}';

    protected $description = 'Queue again a failed, stuck or unroutable gateway event (or every failed one).';

    public function handle(ProviderEventInbox $inbox, RecoverProviderEvent $recover): int
    {
        $id = $this->argument('id');

        if (is_string($id) && $id !== '') {
            $event = str_starts_with($id, 'evt_') ? $inbox->find(GatewayProvider::Stripe, $id) : $inbox->findById($id);

            if ($event === null) {
                $this->components->error('No gateway event with that ID.');

                return self::FAILURE;
            }

            $this->report([$event], $recover);

            return self::SUCCESS;
        }

        if (! (bool) $this->option('failed')) {
            $this->components->error('Pass an event ID or --failed.');

            return self::INVALID;
        }

        $this->report($inbox->withStatus(ProviderEventStatus::Failed, self::BATCH), $recover);

        return self::SUCCESS;
    }

    /**
     * @param  iterable<ProviderEvent>  $events
     */
    private function report(iterable $events, RecoverProviderEvent $recover): void
    {
        $counts = [RecoverProviderEvent::REQUEUED => 0, RecoverProviderEvent::REROUTED => 0, RecoverProviderEvent::SKIPPED => 0];

        foreach ($events as $event) {
            $counts[$recover->handle($event, includeFailed: true)]++;
        }

        $this->components->info("Requeued {$counts['requeued']} event(s); routed {$counts['rerouted']}; left {$counts['skipped']} unchanged.");
    }
}
