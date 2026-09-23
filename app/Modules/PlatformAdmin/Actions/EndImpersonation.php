<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\PlatformAdmin\Enums\ImpersonationEndReason;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Ends an impersonation session (stopped by the admin, expired or invalid)
 * and audits it in the platform and tenant logs.
 */
final readonly class EndImpersonation
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(string $impersonationId, ImpersonationEndReason $reason): void
    {
        DB::transaction(function () use ($impersonationId, $reason): void {
            $session = ImpersonationSession::query()
                ->withoutGlobalScope(TenantScope::class)
                ->lockForUpdate()
                ->find($impersonationId);

            if ($session === null || $session->ended_at !== null) {
                return;
            }

            $session->forceFill(['ended_at' => now(), 'end_reason' => $reason->value])->save();

            $actor = Actor::platformAdmin($session->platform_admin_id);
            $changes = ['impersonation_id' => $session->id, 'user_id' => $session->user_id, 'end_reason' => $reason->value];
            $this->audit->record(AuditAction::ImpersonationEnded, $session, [...$changes, 'tenant_id' => $session->tenant_id], platform: true, actor: $actor);
            $this->audit->record(AuditAction::ImpersonationEnded, $session, $changes, tenantId: $session->tenant_id, actor: $actor);
        });
    }
}
