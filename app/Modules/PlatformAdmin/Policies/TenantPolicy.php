<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Policies;

use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Tenants are managed from the admin panel only. `support_readonly` can look;
 * only `superadmin` creates tenants, edits their profile, manages their
 * invitations and changes their status (plan 17.4, 21.3, ADR-0043).
 *
 * Tenants are never deleted: audit_logs reference them with ON DELETE
 * RESTRICT and the audit trail is append-only. A tenant is retired by
 * closing it.
 */
final class TenantPolicy
{
    public function viewAny(PlatformAdmin $admin): bool
    {
        return true;
    }

    public function view(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    /** Users and invitations of the tenant (read-only lists). */
    public function viewMembers(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function create(PlatformAdmin $admin): bool
    {
        return $admin->isSuperadmin();
    }

    /** Profile only; a closed tenant is frozen. */
    public function update(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return $admin->isSuperadmin() && $tenant->status !== TenantStatus::Closed;
    }

    /** Invite an owner or resend an invitation: not into a closed tenant. */
    public function sendInvitations(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return $admin->isSuperadmin() && $tenant->status->allowsPanelAccess();
    }

    /** Revoking stays possible after closing, to kill a pending link. */
    public function revokeInvitations(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return $admin->isSuperadmin();
    }

    public function changeStatus(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return $admin->isSuperadmin() && $tenant->status->allowedTransitions() !== [];
    }

    public function delete(PlatformAdmin $admin, Tenant $tenant): bool
    {
        return false;
    }

    public function deleteAny(PlatformAdmin $admin): bool
    {
        return false;
    }
}
