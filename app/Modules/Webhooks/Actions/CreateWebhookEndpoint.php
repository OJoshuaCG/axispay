<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\Services\TenantLock;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Data\IssuedWebhookEndpoint;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Enums\WebhookEndpointChange;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\DestinationGuard;
use App\Modules\Webhooks\Services\EnabledEvents;
use App\Modules\Webhooks\Services\WebhookEndpointNotifier;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Registers a webhook endpoint in the panel's current mode (plan 15.1):
 * `webhooks:manage` + re-authentication (plan 17.3), at most 5 per mode, the
 * URL checked by the SSRF protection (plan 15.7). The secret `whsec_...` is
 * generated here, stored encrypted and returned in plaintext ONCE. E-mails
 * the owners and the users with `webhooks:manage`.
 */
final readonly class CreateWebhookEndpoint
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private TenantAccess $access,
        private TenantContext $context,
        private TenantLock $tenantLock,
        private DestinationGuard $guard,
        private WebhookSigner $signer,
        private AuditLogger $audit,
        private WebhookEndpointNotifier $notifier,
    ) {}

    /**
     * @throws WebhookEndpointNotAllowedException
     * @throws UnsafeDestinationException
     */
    public function handle(User $actor, WebhookEndpointData $data): IssuedWebhookEndpoint
    {
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('create', WebhookEndpoint::class);
        $this->reauthentication->ensureConfirmed();

        $livemode = $this->context->livemode();
        $description = WebhookEndpointInput::description($data->description);
        $events = EnabledEvents::normalize($data->events);
        $url = $this->guard->inspect($data->url, $livemode)->url;
        $secret = $this->signer->generateSecret();

        $endpoint = DB::transaction(function () use ($actor, $livemode, $url, $description, $events, $secret): WebhookEndpoint {
            // Serializes concurrent creations, so the limit holds.
            $this->tenantLock->lock($actor->tenant_id);

            if (WebhookEndpoint::query()->count() >= max(1, config()->integer('axispay.webhooks.max_endpoints_per_mode'))) {
                throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TooManyEndpoints);
            }

            $endpoint = new WebhookEndpoint;
            $endpoint->forceFill([
                'livemode' => $livemode,
                'url' => $url,
                'description' => $description,
                'enabled_events' => $events,
                'secret' => $secret,
                'status' => WebhookEndpointStatus::Enabled,
                'created_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::WebhookEndpointCreated, $endpoint, [
                'host' => $endpoint->host(),
                'enabled_events' => $events,
                'livemode' => $livemode,
            ], actor: Actor::user($actor->id));

            return $endpoint;
        });

        $this->notifier->notify($endpoint, WebhookEndpointChange::Created);

        return new IssuedWebhookEndpoint($endpoint, $secret);
    }
}
