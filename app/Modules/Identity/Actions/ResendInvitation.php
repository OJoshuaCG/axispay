<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Identity\Services\InvitationMailer;
use App\Modules\Identity\Services\InvitationThrottle;
use App\Modules\Identity\Services\OpaqueTokens;
use App\Modules\Identity\Services\UserDirectory;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Issues a new link for a pending or expired invitation (ADR-0043): a new
 * single-use token and a new 72-hour expiry replace the old ones on the same
 * row. Only the SHA-256 of the token is stored, so replacing it invalidates
 * the previous link at once (the lookup by hash no longer finds it, 410).
 * Accepted and revoked invitations are final. Platform only (superadmin),
 * throttled with the tenant's invitation limit, audited.
 */
final readonly class ResendInvitation
{
    public function __construct(
        private TenantContext $context,
        private OpaqueTokens $tokens,
        private UserDirectory $directory,
        private AuditLogger $audit,
        private InvitationThrottle $throttle,
        private InvitationMailer $mailer,
    ) {}

    /**
     * @throws InvitationNotPendingException when the invitation was accepted or revoked
     * @throws EmailNotAvailableException when the address has an account by now
     * @throws InvitationNotAllowedException when the tenant is over the invitation limit
     */
    public function handle(PlatformAdmin $actor, UserInvitation $invitation): UserInvitation
    {
        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        Gate::forUser($actor)->authorize('sendInvitations', $tenant);

        return $this->context->runAsTenant($tenant->id, false, function () use ($actor, $invitation, $tenant): UserInvitation {
            $this->throttle->hit($tenant->id);

            $token = $this->tokens->generate();
            $expiresAt = CarbonImmutable::now()->addHours(InvitationMailer::expiresHours());

            $resent = DB::transaction(function () use ($actor, $invitation, $token, $expiresAt): UserInvitation {
                $locked = UserInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
                $previousStatus = $locked->status();

                if (! $previousStatus->canBeResent()) {
                    throw new InvitationNotPendingException;
                }

                if ($this->directory->emailIsRegistered($locked->email)) {
                    throw new EmailNotAvailableException;
                }

                $locked->forceFill([
                    'token_hash' => $this->tokens->hash($token),
                    'expires_at' => $expiresAt,
                ])->save();

                $this->audit->record(AuditAction::InvitationResent, $locked, [
                    'role' => $locked->role_name,
                    'previous_status' => $previousStatus->value,
                    'source' => 'platform',
                ], actor: Actor::platformAdmin($actor->id));

                return $locked;
            });

            $this->mailer->send($resent->email, $token, SystemRole::from($resent->role_name), $expiresAt, $tenant->id);

            return $resent;
        });
    }
}
