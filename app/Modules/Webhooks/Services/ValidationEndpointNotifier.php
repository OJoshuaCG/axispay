<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantOwners;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Notifications\ValidationEndpointNotification;
use Illuminate\Support\Facades\Notification;

/**
 * E-mails the owners and the active users with `webhooks:manage` about the
 * validation endpoint (plan 15.8.5, 17.3). Permissions, never role names
 * (ADR-014). The e-mail names the URL's host only.
 */
final readonly class ValidationEndpointNotifier
{
    public function __construct(
        private TenantOwners $owners,
        private TenantContext $context,
    ) {}

    public function notify(ValidationEndpoint $endpoint, ValidationEndpointChange $change): void
    {
        $recipients = $this->context->runAsTenant($endpoint->tenant_id, $endpoint->livemode, function () use ($endpoint) {
            $tenant = Tenant::query()->findOrFail($endpoint->tenant_id);
            $managers = User::permission(TenantPermission::WebhooksManage->value)->whereNull('disabled_at')->get();

            return $this->owners->of($tenant)->merge($managers)->unique('id')->values();
        });

        Notification::send($recipients, new ValidationEndpointNotification($change, $endpoint->host(), $endpoint->livemode, $endpoint->consecutive_failures));
    }
}
