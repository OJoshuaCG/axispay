<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;

/**
 * Plan 17.1 / 17.3: API keys are listed, created and revoked with
 * `api_keys:manage` (permission, never role names; ADR-014). Creating and
 * revoking also need the re-authentication window, checked in the actions.
 * Another tenant's key is never loaded (tenant scope, 404).
 *
 * A `suspended` or `closed` tenant's panel is read-only (plan 21.3,
 * ADR-013): no new keys. Revoking stays allowed, because it only removes
 * access (ADR-0048).
 */
final readonly class ApiKeyPolicy
{
    public function __construct(private TenantAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::ApiKeysManage->value);
    }

    public function view(User $actor, ApiKey $key): bool
    {
        return $actor->tenant_id === $key->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor) && $this->access->panelWritable($actor->tenant_id);
    }

    /**
     * Not on the impersonation read-only list (plan 17.4). Revoking an
     * already revoked key is allowed and changes nothing.
     */
    public function revoke(User $actor, ApiKey $key): bool
    {
        return $this->view($actor, $key);
    }

    public function update(User $actor, ApiKey $key): bool
    {
        return false;
    }

    public function delete(User $actor, ApiKey $key): bool
    {
        return false;
    }
}
