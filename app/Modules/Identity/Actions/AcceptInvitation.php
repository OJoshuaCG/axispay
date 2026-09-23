<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Data\AcceptInvitationData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Services\InvitationLookup;
use App\Modules\Identity\Services\UserDirectory;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Turns a pending invitation into a tenant user (plan 17.3). The invitation
 * row is locked, so a token can be used exactly once even with concurrent
 * submissions; expired, revoked or used invitations are rejected.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private InvitationLookup $lookup,
        private UserDirectory $directory,
        private TenantContext $context,
        private AuditLogger $audit,
        private RoleGrantGuard $grants,
    ) {}

    public function handle(AcceptInvitationData $data): User
    {
        $found = $this->lookup->findByToken($data->token) ?? throw new InvitationNotPendingException;

        $user = $this->context->runAsTenant($found->tenant_id, false, fn (): ?User => DB::transaction(function () use ($found, $data): ?User {
            $invitation = UserInvitation::query()->lockForUpdate()->findOrFail($found->id);

            if (! $invitation->isPending()) {
                throw new InvitationNotPendingException;
            }

            $role = SystemRole::from($invitation->role_name);

            // Re-check the grant against the inviter's CURRENT permissions: an
            // inviter who lost them (or was deactivated) cannot grant the role.
            if ($invitation->invited_by_user_id !== null) {
                $inviter = User::query()->find($invitation->invited_by_user_id);

                if ($inviter === null || ! $this->grants->canGrant($inviter, $role)) {
                    $invitation->forceFill(['revoked_at' => now()])->save();
                    $this->audit->record(AuditAction::InvitationRevoked, $invitation, ['reason' => 'inviter_cannot_grant'], actor: Actor::system());

                    return null;
                }
            }

            if ($this->directory->emailIsRegistered($invitation->email)) {
                throw new EmailNotAvailableException;
            }

            $user = new User([
                'name' => trim($data->name),
                'email' => $invitation->email,
                'password' => $data->password,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole($role->value);

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            $actor = Actor::user($user->id);
            $this->audit->record(AuditAction::InvitationAccepted, $invitation, ['role' => $role->value], actor: $actor);
            $this->audit->record(AuditAction::RoleAssigned, $user, ['role' => $role->value, 'source' => 'invitation'], actor: $actor);

            return $user;
        }));

        return $user ?? throw new InvitationNotPendingException;
    }
}
