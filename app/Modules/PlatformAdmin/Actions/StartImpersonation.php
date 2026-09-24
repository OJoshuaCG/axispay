<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OpaqueTokens;
use App\Modules\PlatformAdmin\Data\StartedImpersonation;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Starts an audited "view as tenant" session (plan 17.4): superadmin only,
 * mandatory reason, 30-minute limit, read-only. Audited in the platform log
 * and in the tenant's log. The admin reaches the app host through a signed,
 * single-use hand-off link, because the two hosts never share a session.
 */
final readonly class StartImpersonation
{
    public function __construct(
        private OpaqueTokens $tokens,
        private AuditLogger $audit,
    ) {}

    public function handle(PlatformAdmin $actor, User $target, string $reason): StartedImpersonation
    {
        Gate::forUser($actor)->authorize('impersonate', $target);

        $reason = trim($reason);

        if ($reason === '') {
            throw ImpersonationNotAllowedException::reasonRequired();
        }

        if ($target->isDisabled() || $target->tenant === null || ! $target->tenant->status->allowsPanelAccess()) {
            throw ImpersonationNotAllowedException::inactiveTarget();
        }

        $token = $this->tokens->generate();
        $now = CarbonImmutable::now();

        $session = DB::transaction(function () use ($actor, $target, $reason, $token, $now): ImpersonationSession {
            $session = new ImpersonationSession;
            $session->forceFill([
                'tenant_id' => $target->tenant_id,
                'user_id' => $target->id,
                'platform_admin_id' => $actor->id,
                'reason' => $reason,
                'token_hash' => $this->tokens->hash($token),
                'expires_at' => $now->addMinutes($this->maxMinutes()),
            ])->save();

            $changes = ['impersonation_id' => $session->id, 'user_id' => $target->id, 'reason' => $reason];
            $auditActor = Actor::platformAdmin($actor->id);
            $this->audit->record(AuditAction::ImpersonationStarted, $session, [...$changes, 'tenant_id' => $target->tenant_id], platform: true, actor: $auditActor);
            $this->audit->record(AuditAction::ImpersonationStarted, $session, $changes, tenantId: $target->tenant_id, actor: $auditActor);

            return $session;
        });

        return new StartedImpersonation(
            $session,
            URL::temporarySignedRoute('impersonation.consume', $now->addSeconds($this->handoffSeconds()), ['token' => $token]),
        );
    }

    private function maxMinutes(): int
    {
        $minutes = config('axispay.impersonation.max_minutes', 30);

        return is_int($minutes) ? min(30, max(1, $minutes)) : 30;
    }

    private function handoffSeconds(): int
    {
        $seconds = config('axispay.impersonation.handoff_seconds', 120);

        return is_int($seconds) ? max(10, $seconds) : 120;
    }
}
