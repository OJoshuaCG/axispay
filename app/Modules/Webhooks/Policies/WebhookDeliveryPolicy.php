<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Webhooks\Models\WebhookDelivery;

/**
 * The delivery log (plan 15.1) is read with `webhooks:manage`; resending is
 * authorized on the endpoint (WebhookEndpointPolicy::resend). Rows are never
 * edited or deleted from the panel.
 */
final readonly class WebhookDeliveryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::WebhooksManage->value);
    }

    public function view(User $actor, WebhookDelivery $delivery): bool
    {
        return $actor->tenant_id === $delivery->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, WebhookDelivery $delivery): bool
    {
        return false;
    }

    public function delete(User $actor, WebhookDelivery $delivery): bool
    {
        return false;
    }
}
