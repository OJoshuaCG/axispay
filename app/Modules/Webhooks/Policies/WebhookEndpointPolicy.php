<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Models\WebhookEndpoint;

/**
 * Plan 15.1 / 17.1: webhook endpoints and their delivery logs are managed
 * with `webhooks:manage` (a permission, never role names; ADR-014).
 * Creating, editing, rotating, revealing, enabling and deleting also need
 * the re-authentication window, checked in the actions. Another tenant's
 * endpoint is never loaded (tenant scope, 404).
 *
 * A `suspended` or `closed` tenant's panel is read-only (plan 21.3): no new
 * endpoints, no changes, no sends. Disabling and deleting stay allowed:
 * they only stop traffic.
 */
final readonly class WebhookEndpointPolicy
{
    public function __construct(private TenantAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::WebhooksManage->value);
    }

    public function view(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $actor->tenant_id === $endpoint->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor) && $this->access->panelWritable($actor->tenant_id);
    }

    public function update(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $this->view($actor, $endpoint) && $this->access->panelWritable($actor->tenant_id);
    }

    public function revealSecret(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $this->view($actor, $endpoint);
    }

    public function sendTest(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $this->update($actor, $endpoint);
    }

    public function resend(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $this->update($actor, $endpoint);
    }

    public function disable(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $this->view($actor, $endpoint);
    }

    public function delete(User $actor, WebhookEndpoint $endpoint): bool
    {
        return $this->view($actor, $endpoint);
    }
}
