<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Shared\Database\Transactions;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use LogicException;

/**
 * Records a business event in the current tenant context and inside the
 * caller's transaction, so the event exists exactly when the change does
 * (outbox rule, rules.md rule 7). `data` must carry our identifiers and facts
 * only: never payer data or gateway identifiers.
 *
 * In the same transaction the event is published to the outgoing webhook
 * outbox (plan 15.4, ADR-0057): its frozen webhook event and one pending
 * delivery per subscribed endpoint; the jobs are queued after the commit.
 */
final readonly class DomainEventRecorder
{
    public function __construct(private WebhookOutbox $outbox) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(DomainEventType $type, string $subjectType, string $subjectId, array $data): DomainEvent
    {
        if (! Transactions::open()) {
            throw new LogicException('Domain events are recorded inside the transaction of the change.');
        }

        $event = new DomainEvent;
        $event->forceFill([
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'data' => $data,
            'occurred_at' => now(),
        ])->save();

        $this->outbox->publish($event);

        return $event;
    }
}
