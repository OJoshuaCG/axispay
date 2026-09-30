<?php

declare(strict_types=1);

namespace App\Modules\Branding\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;

/**
 * The merchant's logo (ADR-0056 part B): seen and changed with
 * `settings:manage`, the plan 17.1 permission for the merchant's branding
 * (owner and admin; permission, never role names, ADR-014). Another
 * tenant's logo is never loaded (tenant scope). A `suspended` or `closed`
 * tenant's panel is read-only (plan 21.3), and an impersonation session is
 * denied `manage` before this policy runs (plan 17.4).
 */
final readonly class TenantLogoPolicy
{
    public function __construct(private TenantAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::SettingsManage->value);
    }

    public function manage(User $actor): bool
    {
        return $this->viewAny($actor) && $this->access->panelWritable($actor->tenant_id);
    }
}
