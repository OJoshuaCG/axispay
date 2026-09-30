<?php

declare(strict_types=1);

namespace App\Modules\Legal\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantAccess;

/**
 * The merchant's legal documents (ADR-0056): seen and changed with
 * `legal:manage` (permission, never role names; ADR-014). Another tenant's
 * documents are never loaded (tenant scope). A `suspended` or `closed`
 * tenant's panel is read-only (plan 21.3), and an impersonation session is
 * denied `manage` before this policy runs (plan 17.4).
 */
final readonly class TenantLegalDocumentPolicy
{
    public function __construct(private TenantAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::LegalManage->value);
    }

    public function manage(User $actor): bool
    {
        return $this->viewAny($actor) && $this->access->panelWritable($actor->tenant_id);
    }
}
