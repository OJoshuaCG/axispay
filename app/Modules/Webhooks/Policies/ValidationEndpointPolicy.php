<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Models\ValidationEndpoint;

/**
 * Plan 15.8.1 / 17.1: the pre-payment validation URL is managed with
 * `webhooks:manage` (a permission, never role names; ADR-014). Configuring,
 * rotating and removing also need the re-authentication window, checked in
 * the actions. Another tenant's endpoint is never loaded (tenant scope, 404).
 *
 * A `suspended` or `closed` tenant's panel is read-only (plan 21.3): no
 * configuration, rotation or test. Removing stays allowed.
 */
final readonly class ValidationEndpointPolicy
{
    public function __construct(private TenantAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::WebhooksManage->value);
    }

    public function view(User $actor, ValidationEndpoint $endpoint): bool
    {
        return $actor->tenant_id === $endpoint->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor) && $this->access->panelWritable($actor->tenant_id);
    }

    public function update(User $actor, ValidationEndpoint $endpoint): bool
    {
        return $this->view($actor, $endpoint) && $this->access->panelWritable($actor->tenant_id);
    }

    public function test(User $actor, ValidationEndpoint $endpoint): bool
    {
        return $this->update($actor, $endpoint);
    }

    public function delete(User $actor, ValidationEndpoint $endpoint): bool
    {
        return $this->view($actor, $endpoint);
    }
}
