<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantStatusTransitionException;
use App\Modules\Tenancy\Exceptions\TenantCloseNotConfirmedException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Notifications\TenantStatusChangedNotification;
use App\Modules\Tenancy\Services\TenantOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The only way to change a tenant's status (plan 21.3): superadmin only,
 * reason required, allowed transitions only, closing double-confirmed,
 * audited, owners notified. Runs under a row lock (rules.md rule 8).
 *
 * handleAsSystem() is the same path for the automatic transitions of plan
 * 21.3 ("automático al completar onboarding"): no authorization (there is no
 * user), audited with the system actor. Closing is never automatic.
 */
final readonly class ChangeTenantStatus
{
    public function __construct(
        private AuditLogger $audit,
        private TenantOwners $owners,
    ) {}

    public function handle(PlatformAdmin $actor, Tenant $tenant, ChangeTenantStatusData $data): Tenant
    {
        Gate::forUser($actor)->authorize('changeStatus', $tenant);

        return $this->transition($tenant, $data, Actor::platformAdmin($actor->id));
    }

    /**
     * Automatic transition decided by the platform itself (for example
     * pending_onboarding -> active once a gateway connection can charge).
     * `$expectedFrom` guards against racing a superadmin: nothing happens
     * when the tenant is no longer in that status.
     */
    public function handleAsSystem(Tenant $tenant, ChangeTenantStatusData $data, TenantStatus $expectedFrom): ?Tenant
    {
        if ($data->status->requiresDoubleConfirmation()) {
            throw new InvalidArgumentException('Closing a tenant is never automatic.');
        }

        return $this->transition($tenant, $data, Actor::system(), $expectedFrom);
    }

    /**
     * @return ($expectedFrom is null ? Tenant : Tenant|null)
     */
    private function transition(Tenant $tenant, ChangeTenantStatusData $data, Actor $actor, ?TenantStatus $expectedFrom = null): ?Tenant
    {
        $reason = trim($data->reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to change the tenant status.');
        }

        $updated = DB::transaction(function () use ($actor, $tenant, $data, $reason, $expectedFrom): ?Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $from = $locked->status;

            if ($expectedFrom !== null && $from !== $expectedFrom) {
                return null;
            }

            if (! $from->canTransitionTo($data->status)) {
                throw new InvalidTenantStatusTransitionException($from, $data->status);
            }

            if ($data->status->requiresDoubleConfirmation() && $data->closeConfirmation !== $locked->display_name) {
                throw new TenantCloseNotConfirmedException;
            }

            $locked->forceFill([
                'status' => $data->status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'closed_at' => $data->status === TenantStatus::Closed ? now() : $locked->closed_at,
            ])->save();

            $this->audit->record(AuditAction::TenantStatusChanged, $locked, [
                'before' => ['status' => $from->value],
                'after' => ['status' => $data->status->value],
                'reason' => $reason,
            ], tenantId: $locked->id, actor: $actor);

            return $locked;
        });

        if ($updated === null) {
            return null;
        }

        if (in_array($updated->status, [TenantStatus::Grace, TenantStatus::Suspended, TenantStatus::Closed], true)) {
            Notification::send($this->owners->of($updated), new TenantStatusChangedNotification($updated->status));
        }

        return $updated;
    }
}
