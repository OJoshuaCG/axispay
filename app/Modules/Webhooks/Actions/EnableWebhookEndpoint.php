<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\DestinationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Enables an endpoint disabled by the user or by failures again:
 * `webhooks:manage` + re-authentication (it starts sending data again, plan
 * 17.3). The URL passes the SSRF protection again and the failure streak
 * starts over. Only events created from now on are sent; missed ones can be
 * resent from the delivery log.
 */
final readonly class EnableWebhookEndpoint
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private TenantAccess $access,
        private DestinationGuard $guard,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws UnsafeDestinationException
     */
    public function handle(User $actor, WebhookEndpoint $endpoint): WebhookEndpoint
    {
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('update', $endpoint);
        $this->reauthentication->ensureConfirmed();
        $this->guard->inspect($endpoint->url, $endpoint->livemode);

        return DB::transaction(function () use ($actor, $endpoint): WebhookEndpoint {
            $locked = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);

            if ($locked->isEnabled()) {
                return $locked;
            }

            $previous = $locked->status;
            $locked->forceFill([
                'status' => WebhookEndpointStatus::Enabled,
                'failing_since' => null,
                'disabled_at' => null,
            ])->save();

            $this->audit->record(AuditAction::WebhookEndpointEnabled, $locked, [
                'host' => $locked->host(),
                'livemode' => $locked->livemode,
                'status_before' => $previous->value,
            ], actor: Actor::user($actor->id));

            return $locked;
        });
    }
}
