<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Enums;

/**
 * Derived ownership state of a tenant (plan 17.2, ADR-0045). Not stored: it
 * follows from the tenant's users, their `owner` role assignments and its
 * pending owner invitations (TenantOwnership).
 */
enum TenantOwnershipState: string
{
    /** At least one active (not deactivated) user holds the owner role. */
    case Active = 'active';

    /** No active owner, but an owner invitation is still valid. */
    case PendingInvitation = 'pending_invitation';

    /** No active owner and no valid owner invitation. */
    case None = 'none';

    public function label(): string
    {
        return __('platform.tenants.ownership.state.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingInvitation => 'info',
            self::None => 'danger',
        };
    }
}
