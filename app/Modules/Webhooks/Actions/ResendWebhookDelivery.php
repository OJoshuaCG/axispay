<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Manual resend from the delivery log (plan 15.6): a new attempt of the same
 * event to the same endpoint, with the same frozen body and `webhook-id`
 * (a fresh timestamp and signature). A single attempt, queued now; it does
 * not restart the automatic schedule. The endpoint must be enabled.
 */
final readonly class ResendWebhookDelivery
{
    public function __construct(
        private TenantAccess $access,
        private WebhookDispatcher $dispatcher,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, WebhookDelivery $delivery): WebhookDelivery
    {
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        $endpoint = WebhookEndpoint::query()->findOrFail($delivery->webhook_endpoint_id);
        Gate::forUser($actor)->authorize('resend', $endpoint);

        $attempt = DB::transaction(function () use ($actor, $delivery): WebhookDelivery {
            $locked = WebhookEndpoint::query()->lockForUpdate()->findOrFail($delivery->webhook_endpoint_id);

            if (! $locked->isEnabled()) {
                throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::EndpointDisabled);
            }

            $last = WebhookDelivery::query()
                ->where('webhook_event_id', $delivery->webhook_event_id)
                ->where('webhook_endpoint_id', $locked->id)
                ->where('trigger', WebhookDeliveryTrigger::Manual->value)
                ->max('attempt_number');

            $attempt = new WebhookDelivery;
            $attempt->forceFill([
                'tenant_id' => $locked->tenant_id,
                'livemode' => $locked->livemode,
                'webhook_event_id' => $delivery->webhook_event_id,
                'webhook_endpoint_id' => $locked->id,
                'trigger' => WebhookDeliveryTrigger::Manual,
                'attempt_number' => (is_numeric($last) ? (int) $last : 0) + 1,
                'status' => WebhookDeliveryStatus::Pending,
                'scheduled_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditAction::WebhookDeliveryResent, $attempt, [
                'webhook_event_id' => $delivery->webhook_event_id,
                'host' => $locked->host(),
                'livemode' => $locked->livemode,
            ], actor: Actor::user($actor->id));

            DB::afterCommit(fn () => $this->dispatcher->dispatch($attempt->id, $attempt->tenant_id, $attempt->livemode));

            return $attempt;
        });

        return WebhookDelivery::query()->findOrFail($attempt->id);
    }
}
