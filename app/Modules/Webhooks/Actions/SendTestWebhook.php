<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use App\Modules\Webhooks\Services\WebhookPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * "Send test event" (plan 15.1): a `ping` signed like any event, sent NOW,
 * synchronously (at most the 10-second budget), so the panel shows the
 * result: status, HTTP code, latency, excerpt or error. Also allowed for a
 * disabled endpoint (to check it before enabling it). A single attempt that
 * never changes the endpoint's health. Never call it inside a transaction.
 */
final readonly class SendTestWebhook
{
    public function __construct(
        private TenantAccess $access,
        private DeliverWebhook $deliver,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, WebhookEndpoint $endpoint): WebhookDelivery
    {
        if (Transactions::open()) {
            throw new LogicException('A test webhook is sent outside any database transaction.');
        }

        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('sendTest', $endpoint);

        $delivery = DB::transaction(function () use ($actor, $endpoint): WebhookDelivery {
            $now = CarbonImmutable::now();
            $id = Ulid::generate();

            $event = new WebhookEvent;
            $event->forceFill([
                'id' => $id,
                'tenant_id' => $endpoint->tenant_id,
                'livemode' => $endpoint->livemode,
                'type' => WebhookEventType::Ping,
                'payload' => WebhookPayload::ping($id, $endpoint->livemode, $now),
                'dispatched_at' => $now,
            ])->save();

            $delivery = new WebhookDelivery;
            $delivery->forceFill([
                'tenant_id' => $endpoint->tenant_id,
                'livemode' => $endpoint->livemode,
                'webhook_event_id' => $event->id,
                'webhook_endpoint_id' => $endpoint->id,
                'trigger' => WebhookDeliveryTrigger::Test,
                'attempt_number' => 1,
                'status' => WebhookDeliveryStatus::Pending,
                'scheduled_at' => $now,
            ])->save();

            $this->audit->record(AuditAction::WebhookEndpointTestSent, $endpoint, [
                'host' => $endpoint->host(),
                'livemode' => $endpoint->livemode,
            ], actor: Actor::user($actor->id));

            return $delivery;
        });

        return $this->deliver->handle($delivery->id) ?? $delivery;
    }
}
