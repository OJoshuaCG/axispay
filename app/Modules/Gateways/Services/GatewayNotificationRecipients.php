<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantOwners;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recipients of gateway e-mails (plan 17.3, 22): the tenant's owners plus
 * every active user holding `gateway:manage`.
 */
final readonly class GatewayNotificationRecipients
{
    public function __construct(
        private TenantContext $context,
        private TenantOwners $owners,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function of(string $tenantId): Collection
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $managers = $this->context->runAsTenant(
            $tenant->id,
            $this->context->livemodeOrNull() ?? false,
            static fn (): Collection => User::permission(TenantPermission::GatewayManage->value)->whereNull('disabled_at')->get(),
        );

        return $this->owners->of($tenant)->merge($managers)->unique('id')->values();
    }
}
