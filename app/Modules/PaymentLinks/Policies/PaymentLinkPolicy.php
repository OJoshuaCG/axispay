<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Services\TenantAccess;

/**
 * Plan 17.1: `links:read`, `links:create`, `links:cancel` (permissions,
 * never role names; ADR-014). Another tenant's link is never loaded (tenant
 * scope, 404). Creating and canceling from the panel also need a tenant
 * whose panel is not read-only (TenantAccess, plan 21.3); the actions'
 * panel entry points check it again.
 */
final readonly class PaymentLinkPolicy
{
    public function __construct(private TenantAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::LinksRead->value);
    }

    public function view(User $actor, PaymentLink $link): bool
    {
        return $actor->tenant_id === $link->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::LinksCreate->value)
            && $this->access->panelWritable($actor->tenant_id);
    }

    public function cancel(User $actor, PaymentLink $link): bool
    {
        return $actor->tenant_id === $link->tenant_id
            && $link->status === PaymentLinkStatus::Active
            && $actor->checkPermissionTo(TenantPermission::LinksCancel->value)
            && $this->access->panelWritable($actor->tenant_id);
    }

    public function update(User $actor, PaymentLink $link): bool
    {
        return false;
    }

    public function delete(User $actor, PaymentLink $link): bool
    {
        return false;
    }
}
