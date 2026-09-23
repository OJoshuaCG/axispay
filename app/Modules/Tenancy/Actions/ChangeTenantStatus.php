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

        $reason = trim($data->reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to change the tenant status.');
        }

        $updated = DB::transaction(function () use ($actor, $tenant, $data, $reason): Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $from = $locked->status;

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
            ], tenantId: $locked->id, actor: Actor::platformAdmin($actor->id));

            return $locked;
        });

        if (in_array($updated->status, [TenantStatus::Grace, TenantStatus::Suspended, TenantStatus::Closed], true)) {
            Notification::send($this->owners->of($updated), new TenantStatusChangedNotification($updated->status));
        }

        return $updated;
    }
}
