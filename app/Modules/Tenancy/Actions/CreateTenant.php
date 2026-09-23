<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Actions\InviteUser;
use App\Modules\Identity\Data\InviteUserData;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Data\CreateTenantData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a tenant in `pending_onboarding` (plan 21.3) and, optionally,
 * invites its first owner. Superadmin only; audited.
 */
final readonly class CreateTenant
{
    public function __construct(
        private AuditLogger $audit,
        private TenantContext $context,
        private InviteUser $inviteUser,
    ) {}

    public function handle(PlatformAdmin $actor, CreateTenantData $data): Tenant
    {
        Gate::forUser($actor)->authorize('create', Tenant::class);

        return DB::transaction(function () use ($actor, $data): Tenant {
            $tenant = new Tenant([
                'legal_name' => $data->legalName,
                'display_name' => $data->displayName,
                'timezone' => $data->timezone,
                'default_locale' => $data->defaultLocale,
                'support_email' => $data->supportEmail,
            ]);
            $tenant->forceFill([
                'status' => TenantStatus::PendingOnboarding,
                'status_changed_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::TenantCreated, $tenant, [
                'display_name' => $tenant->display_name,
                'status' => $tenant->status->value,
            ], tenantId: $tenant->id, actor: Actor::platformAdmin($actor->id));

            if ($data->ownerEmail !== null) {
                $this->context->runAsTenant($tenant->id, false, fn () => $this->inviteUser->handle(
                    new InviteUserData(email: $data->ownerEmail, role: SystemRole::Owner),
                    invitedBy: null,
                ));
            }

            return $tenant;
        });
    }
}
