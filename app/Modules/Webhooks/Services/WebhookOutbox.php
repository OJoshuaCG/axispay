<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Fan-out of a business event into the outgoing webhook outbox (plan 15.4):
 * inside the SAME transaction as the change, the frozen `webhook_events` row
 * and one `pending` delivery per enabled endpoint of the tenant and mode
 * subscribed to the type; after the commit, one DeliverWebhookJob per
 * delivery. The domain event is marked `published_at`.
 *
 * An endpoint receives only the events that happened once it existed
 * (ADR-0057): a backlog the sweeper publishes late (events recorded before
 * Phase 5, or whose publication failed) never floods an endpoint registered
 * afterwards.
 *
 * Idempotent per domain event (unique `domain_event_id`): the recorder and
 * the sweeper never publish the same event twice.
 */
final readonly class WebhookOutbox
{
    public function __construct(private WebhookDispatcher $dispatcher) {}

    public function publish(DomainEvent $domainEvent): WebhookEvent
    {
        if (! Transactions::open()) {
            throw new LogicException('Webhook events are published inside the transaction of the change.');
        }

        $existing = WebhookEvent::query()->where('domain_event_id', $domainEvent->id)->first();

        if ($existing !== null) {
            $this->markPublished($domainEvent);

            return $existing;
        }

        $type = WebhookEventType::fromDomain($domainEvent->type);
        $now = CarbonImmutable::now();
        $id = Ulid::generate();

        $event = new WebhookEvent;
        $event->forceFill([
            'id' => $id,
            'tenant_id' => $domainEvent->tenant_id,
            'livemode' => $domainEvent->livemode,
            'type' => $type,
            'domain_event_id' => $domainEvent->id,
            'payload' => WebhookPayload::encode($id, $type, $domainEvent->livemode, $domainEvent->occurred_at, $this->dataOf($domainEvent)),
        ])->save();

        $endpoints = WebhookEndpoint::query()
            ->enabled()
            ->where('livemode', $domainEvent->livemode)
            ->where('created_at', '<=', $domainEvent->occurred_at)
            ->orderBy('id')
            ->get()
            ->filter(static fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($type));

        $deliveries = [];

        foreach ($endpoints as $endpoint) {
            $delivery = new WebhookDelivery;
            $delivery->forceFill([
                'tenant_id' => $endpoint->tenant_id,
                'livemode' => $endpoint->livemode,
                'webhook_event_id' => $event->id,
                'webhook_endpoint_id' => $endpoint->id,
                'trigger' => WebhookDeliveryTrigger::Automatic,
                'attempt_number' => 1,
                'status' => WebhookDeliveryStatus::Pending,
                'scheduled_at' => $now,
            ])->save();

            $deliveries[] = $delivery;
        }

        $this->markPublished($domainEvent);

        DB::afterCommit(function () use ($deliveries, $event): void {
            $this->dispatcher->dispatchAll($deliveries);
            $event->forceFill(['dispatched_at' => CarbonImmutable::now()])->save();
        });

        return $event;
    }

    private function markPublished(DomainEvent $domainEvent): void
    {
        if ($domainEvent->published_at === null) {
            $domainEvent->forceFill(['published_at' => CarbonImmutable::now()])->save();
        }
    }

    /**
     * The stored data decoded as objects, so empty objects (`metadata: {}`)
     * are not turned into lists.
     */
    private function dataOf(DomainEvent $domainEvent): stdClass
    {
        $raw = $domainEvent->getRawOriginal('data');
        $decoded = is_string($raw) ? json_decode($raw, false) : null;

        return $decoded instanceof stdClass ? $decoded : (object) $domainEvent->data;
    }
}
