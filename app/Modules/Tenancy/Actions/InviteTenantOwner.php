<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Actions\InviteUser;
use App\Modules\Identity\Data\InviteUserData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Invites an owner to an existing tenant from the platform panel (ADR-0043),
 * for tenants created without `owner_email` or whose owner never joined.
 * Always the `owner` role: the platform bootstraps a tenant, and the owner
 * invites everyone else from the tenant panel.
 *
 * Same path as CreateTenant: InviteUser inside the tenant's context, with no
 * tenant inviter and the acting superadmin as the audit actor. Platform-wide
 * e-mail uniqueness, the per-tenant throttle and "a new invitation supersedes
 * the pending one" all apply.
 */
final readonly class InviteTenantOwner
{
    public function __construct(
        private TenantContext $context,
        private InviteUser $inviteUser,
    ) {}

    /**
     * @throws EmailNotAvailableException
     * @throws InvitationNotAllowedException when the tenant is over the invitation limit
     */
    public function handle(PlatformAdmin $actor, Tenant $tenant, string $email): UserInvitation
    {
        Gate::forUser($actor)->authorize('sendInvitations', $tenant);

        return $this->context->runAsTenant($tenant->id, false, fn (): UserInvitation => $this->inviteUser->handle(
            new InviteUserData(email: $email, role: SystemRole::Owner),
            invitedBy: null,
            platformAdmin: $actor,
        ));
    }
}
