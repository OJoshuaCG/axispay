<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;

/**
 * The tenant's payment settings (conversion and link expiration, ADR-0063,
 * ADR-0048): seen and changed with `settings:manage` (owner and admin; a
 * permission, never a role name, ADR-014). A `suspended` or `closed` tenant's
 * panel is read-only (plan 21.3) and an impersonation session is denied
 * `manage` before this policy runs (plan 17.4). Registered for
 * TenantSettings, the typed view of the settings it edits.
 */
final readonly class TenantPaymentSettingsPolicy
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
