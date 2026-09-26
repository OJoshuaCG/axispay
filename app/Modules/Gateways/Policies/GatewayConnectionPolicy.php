<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Identity\Models\User;

/**
 * Plan 17.1 / 17.3: the Stripe connection is managed with `gateway:manage`
 * only (permission, never role names; ADR-014). Record-level checks rely on
 * the tenant scope: another tenant's connection is never loaded (404).
 * Sensitive actions also need the re-authentication window, checked inside
 * each action.
 */
final class GatewayConnectionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::GatewayManage->value);
    }

    public function view(User $actor, GatewayConnection $connection): bool
    {
        return $actor->tenant_id === $connection->tenant_id && $this->viewAny($actor);
    }

    public function manage(User $actor, ?GatewayConnection $connection = null): bool
    {
        return ($connection === null || $actor->tenant_id === $connection->tenant_id)
            && $actor->checkPermissionTo(TenantPermission::GatewayManage->value);
    }
}
