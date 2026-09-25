<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Revokes a pending invitation (ADR-0043): its link stops working at once.
 * Platform only (superadmin), under a row lock, audited.
 */
final readonly class RevokeInvitation
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws InvitationNotPendingException when the invitation is no longer pending
     */
    public function handle(PlatformAdmin $actor, UserInvitation $invitation): UserInvitation
    {
        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        Gate::forUser($actor)->authorize('revokeInvitations', $tenant);

        return $this->context->runAsTenant($tenant->id, false, fn (): UserInvitation => DB::transaction(function () use ($actor, $invitation): UserInvitation {
            $locked = UserInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $locked->status()->canBeRevoked()) {
                throw new InvitationNotPendingException;
            }

            $locked->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(AuditAction::InvitationRevoked, $locked, [
                'reason' => 'revoked',
                'source' => 'platform',
            ], actor: Actor::platformAdmin($actor->id));

            return $locked;
        }));
    }
}
