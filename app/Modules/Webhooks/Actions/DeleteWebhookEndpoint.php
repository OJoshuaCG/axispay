<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Webhooks\Enums\WebhookEndpointChange;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookEndpointNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes an endpoint and its delivery log (foreign key cascade):
 * `webhooks:manage` + re-authentication. The events stay (they belong to the
 * tenant's event history, `GET /v1/events`). Allowed for read-only tenants:
 * it only removes a destination. E-mails the owners and the users with
 * `webhooks:manage`.
 */
final readonly class DeleteWebhookEndpoint
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private WebhookEndpointNotifier $notifier,
    ) {}

    public function handle(User $actor, WebhookEndpoint $endpoint): void
    {
        Gate::forUser($actor)->authorize('delete', $endpoint);
        $this->reauthentication->ensureConfirmed();

        $deleted = DB::transaction(function () use ($actor, $endpoint): WebhookEndpoint {
            $locked = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);

            $this->audit->record(AuditAction::WebhookEndpointDeleted, $locked, [
                'host' => $locked->host(),
                'livemode' => $locked->livemode,
            ], actor: Actor::user($actor->id));

            $locked->delete();

            return $locked;
        });

        $this->notifier->notify($deleted, WebhookEndpointChange::Deleted);
    }
}
