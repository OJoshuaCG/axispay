<?php

declare(strict_types=1);

namespace App\Modules\Audit\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * The audit log is read-only for everyone (append-only, plan 7.1). Tenant
 * users need `audit:read` and only ever see their tenant's rows (scope);
 * platform admins see the whole log.
 */
final class AuditLogPolicy
{
    public function viewAny(User|PlatformAdmin $actor): bool
    {
        return $actor instanceof PlatformAdmin || $actor->checkPermissionTo(TenantPermission::AuditRead->value);
    }

    public function view(User|PlatformAdmin $actor, AuditLog $entry): bool
    {
        if ($actor instanceof PlatformAdmin) {
            return true;
        }

        return $entry->tenant_id === $actor->tenant_id && $actor->checkPermissionTo(TenantPermission::AuditRead->value);
    }

    public function create(User|PlatformAdmin $actor): bool
    {
        return false;
    }

    public function update(User|PlatformAdmin $actor, AuditLog $entry): bool
    {
        return false;
    }

    public function delete(User|PlatformAdmin $actor, AuditLog $entry): bool
    {
        return false;
    }

    public function deleteAny(User|PlatformAdmin $actor): bool
    {
        return false;
    }
}
