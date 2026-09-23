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
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\Identity\Services\OpaqueTokens;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Identity\Services\UserDirectory;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Invites someone to the current tenant with a predefined role (plan 17.3):
 * single-use token, 72-hour expiry, signed link. A new invitation for the same
 * e-mail revokes the previous pending one. `invitedBy` is null when the
 * platform creates the owner invitation of a new tenant.
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
    ) {}

    public function handle(InviteUserData $data, ?User $invitedBy): UserInvitation
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

            $this->throttle($tenantId);
        }

        $email = UserDirectory::normalize($data->email);

        if ($this->directory->emailIsRegistered($email)) {
            // Platform-wide unique e-mails make refusals an enumeration signal
            // (accepted risk, ADR-0034): audited and throttled per tenant.
            $this->audit->record(AuditAction::InvitationRefused, changes: [
                'recipient_hash' => hash('sha256', $email),
                'reason' => 'email_not_available',
            ], actor: $invitedBy !== null ? Actor::user($invitedBy->id) : null);

            throw new EmailNotAvailableException;
        }

        $token = $this->tokens->generate();
        $expiresAt = CarbonImmutable::now()->addHours($this->expiresHours());

        $actor = $invitedBy !== null ? Actor::user($invitedBy->id) : null;

        $invitation = DB::transaction(function () use ($email, $data, $token, $expiresAt, $invitedBy, $actor): UserInvitation {
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

            $this->audit->record(AuditAction::InvitationCreated, $invitation, ['role' => $data->role->value], actor: $actor);

            return $invitation;
        });

        $tenant = Tenant::query()->findOrFail($tenantId);

        Notification::route('mail', $email)->notify(new UserInvitationNotification(
            acceptUrl: URL::temporarySignedRoute('invitations.show', $expiresAt, ['token' => $token]),
            tenantName: $tenant->display_name,
            roleLabel: $data->role->label(),
            expiresAt: $expiresAt,
        ));

        return $invitation;
    }

    /**
     * Per-tenant invitation throttle (ADR-0034): every attempt counts,
     * including refused ones.
     */
    private function throttle(string $tenantId): void
    {
        $key = 'invitations:'.$tenantId;
        $max = config('paylink.invitations.max_per_hour', 20);

        if (RateLimiter::tooManyAttempts($key, is_int($max) ? $max : 20)) {
            throw InvitationNotAllowedException::throttled();
        }

        RateLimiter::hit($key, 3600);
    }

    private function expiresHours(): int
    {
        $hours = config('paylink.invitations.expires_hours', 72);

        return is_int($hours) ? $hours : 72;
    }
}
