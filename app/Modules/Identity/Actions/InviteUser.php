<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Data\InviteUserData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Services\InvitationMailer;
use App\Modules\Identity\Services\InvitationThrottle;
use App\Modules\Identity\Services\OpaqueTokens;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Identity\Services\UserDirectory;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Invites someone to the current tenant with a predefined role (plan 17.3):
 * single-use token, 72-hour expiry, signed link. A new invitation for the same
 * e-mail revokes the previous pending one.
 *
 * `invitedBy` is null when the platform invites the owner of a tenant
 * (CreateTenant, InviteTenantOwner). Those callers authorize the platform
 * admin themselves and pass it as `platformAdmin`, so the audit entries name
 * the acting admin; the tenant-user checks (RoleGrantGuard, re-authentication)
 * apply to tenant users only and are not weakened by this path.
 */
final readonly class InviteUser
{
    public function __construct(
        private TenantContext $context,
        private OpaqueTokens $tokens,
        private UserDirectory $directory,
        private AuditLogger $audit,
        private RoleGrantGuard $grants,
        private ReauthenticationWindow $reauthentication,
        private InvitationThrottle $throttle,
        private InvitationMailer $mailer,
    ) {}

    public function handle(InviteUserData $data, ?User $invitedBy, ?PlatformAdmin $platformAdmin = null): UserInvitation
    {
        $tenantId = $this->context->idOrFail(UserInvitation::class);

        if ($invitedBy !== null) {
            Gate::forUser($invitedBy)->authorize('invite', User::class);

            // No escalation through invitations: the inviter must hold every
            // permission of the role (same rule as ChangeUserRoles).
            if (! $this->grants->canGrant($invitedBy, $data->role)) {
                throw InvitationNotAllowedException::roleNotGrantable();
            }

            if ($data->role->isSensitive()) {
                $this->reauthentication->ensureConfirmed();
            }
        }

        if ($invitedBy !== null || $platformAdmin !== null) {
            $this->throttle->hit($tenantId);
        }

        $actor = match (true) {
            $invitedBy !== null => Actor::user($invitedBy->id),
            $platformAdmin !== null => Actor::platformAdmin($platformAdmin->id),
            default => null,
        };

        $email = UserDirectory::normalize($data->email);

        if ($this->directory->emailIsRegistered($email)) {
            // Platform-wide unique e-mails make refusals an enumeration signal
            // (accepted risk, ADR-0034): audited and throttled per tenant.
            $this->audit->record(AuditAction::InvitationRefused, changes: [
                'recipient_hash' => hash('sha256', $email),
                'reason' => 'email_not_available',
            ], actor: $actor);

            throw new EmailNotAvailableException;
        }

        $token = $this->tokens->generate();
        $expiresAt = CarbonImmutable::now()->addHours(InvitationMailer::expiresHours());

        $invitation = DB::transaction(function () use ($email, $data, $token, $expiresAt, $invitedBy, $actor, $platformAdmin): UserInvitation {
            UserInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(function (UserInvitation $previous) use ($actor): void {
                    $previous->forceFill(['revoked_at' => now()])->save();
                    $this->audit->record(AuditAction::InvitationRevoked, $previous, ['reason' => 'superseded'], actor: $actor);
                });

            $invitation = new UserInvitation;
            $invitation->forceFill([
                'email' => $email,
                'role_name' => $data->role->value,
                'token_hash' => $this->tokens->hash($token),
                'expires_at' => $expiresAt,
                'invited_by_user_id' => $invitedBy?->id,
            ])->save();

            $this->audit->record(AuditAction::InvitationCreated, $invitation, array_filter([
                'role' => $data->role->value,
                'source' => $platformAdmin !== null ? 'platform' : null,
            ]), actor: $actor);

            return $invitation;
        });

        $this->mailer->send($email, $token, $data->role, $expiresAt, $tenantId);

        return $invitation;
    }
}
