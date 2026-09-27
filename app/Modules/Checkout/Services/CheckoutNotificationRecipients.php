<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantOwners;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recipients of the "link blocked for card testing" e-mail (plan 22: owners +
 * admins). Expressed with permissions (ADR-014): the owners plus every
 * active user holding `links:cancel`, the permission that lifts the block.
 * Runs in the tenant's context.
 */
final readonly class CheckoutNotificationRecipients
{
    public function __construct(private TenantOwners $owners) {}

    /**
     * @return Collection<int, User>
     */
    public function of(string $tenantId): Collection
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $managers = User::permission(TenantPermission::LinksCancel->value)->whereNull('disabled_at')->get();

        return $this->owners->of($tenant)->merge($managers)->unique('id')->values();
    }
}
