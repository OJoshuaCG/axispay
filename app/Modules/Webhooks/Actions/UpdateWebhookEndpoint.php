<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Enums\WebhookEndpointChange;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\DestinationGuard;
use App\Modules\Webhooks\Services\EnabledEvents;
use App\Modules\Webhooks\Services\WebhookEndpointNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Changes an endpoint's URL, description and events (plan 15.1):
 * `webhooks:manage` + re-authentication (plan 17.3); a new URL passes the
 * SSRF protection again. The secret and the mode never change here. E-mails
 * the owners and the users with `webhooks:manage` when something changed.
 */
final readonly class UpdateWebhookEndpoint
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private TenantAccess $access,
        private DestinationGuard $guard,
        private AuditLogger $audit,
        private WebhookEndpointNotifier $notifier,
    ) {}

    /**
     * @throws WebhookEndpointNotAllowedException
     * @throws UnsafeDestinationException
     */
    public function handle(User $actor, WebhookEndpoint $endpoint, WebhookEndpointData $data): WebhookEndpoint
    {
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('update', $endpoint);
        $this->reauthentication->ensureConfirmed();

        $description = WebhookEndpointInput::description($data->description);
        $events = EnabledEvents::normalize($data->events);
        $url = trim($data->url) === $endpoint->url ? $endpoint->url : $this->guard->inspect($data->url, $endpoint->livemode)->url;

        [$updated, $changed] = DB::transaction(function () use ($actor, $endpoint, $url, $description, $events): array {
            $locked = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);
            $locked->forceFill(['url' => $url, 'description' => $description, 'enabled_events' => $events]);

            if (! $locked->isDirty()) {
                return [$locked, false];
            }

            $changes = ['livemode' => $locked->livemode];

            if ($locked->isDirty('url')) {
                $changes['host'] = $locked->host();
            }

            if ($locked->isDirty('enabled_events')) {
                $changes['enabled_events'] = $events;
            }

            $locked->save();
            $this->audit->record(AuditAction::WebhookEndpointUpdated, $locked, $changes, actor: Actor::user($actor->id));

            return [$locked, true];
        });

        if ($changed) {
            $this->notifier->notify($updated, WebhookEndpointChange::Updated);
        }

        return $updated;
    }
}
