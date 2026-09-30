<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stops sending events to an endpoint (`disabled_by_user`). Allowed for
 * read-only tenants too: it only stops traffic. Pending attempts are
 * dropped when their turn comes (DeliverWebhook). Disabling an already
 * disabled endpoint changes nothing.
 */
final readonly class DisableWebhookEndpoint
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, WebhookEndpoint $endpoint): WebhookEndpoint
    {
        Gate::forUser($actor)->authorize('disable', $endpoint);

        return DB::transaction(function () use ($actor, $endpoint): WebhookEndpoint {
            $locked = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);

            if (! $locked->isEnabled()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => WebhookEndpointStatus::DisabledByUser,
                'disabled_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditAction::WebhookEndpointDisabled, $locked, [
                'host' => $locked->host(),
                'livemode' => $locked->livemode,
            ], actor: Actor::user($actor->id));

            return $locked;
        });
    }
}
