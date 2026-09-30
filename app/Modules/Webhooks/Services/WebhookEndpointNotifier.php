<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantOwners;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\WebhookEndpointChange;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Notifications\WebhookEndpointNotification;
use Illuminate\Support\Facades\Notification;

/**
 * E-mails the owners and the active users with `webhooks:manage` when an
 * endpoint is created, changed, deleted or disabled by failures (plan 17.3,
 * 22). Permissions, never role names (ADR-014). The e-mail names the URL's
 * host only, never the full URL or the secret.
 */
final readonly class WebhookEndpointNotifier
{
    public function __construct(
        private TenantOwners $owners,
        private TenantContext $context,
    ) {}

    public function notify(WebhookEndpoint $endpoint, WebhookEndpointChange $change): void
    {
        $recipients = $this->context->runAsTenant($endpoint->tenant_id, $endpoint->livemode, function () use ($endpoint) {
            $tenant = Tenant::query()->findOrFail($endpoint->tenant_id);
            $managers = User::permission(TenantPermission::WebhooksManage->value)->whereNull('disabled_at')->get();

            return $this->owners->of($tenant)->merge($managers)->unique('id')->values();
        });

        Notification::send($recipients, new WebhookEndpointNotification($change, $endpoint->host(), $endpoint->livemode));
    }
}
