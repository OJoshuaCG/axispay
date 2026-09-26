<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Tenancy\Actions\ChangeTenantStatus;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Plan 21.3: a tenant leaves `pending_onboarding` automatically once one of
 * its gateway connections can charge, in either mode (ADR-0047: test-mode
 * integration must not wait for live onboarding; creating links in a mode
 * still needs a connection that can charge in that mode). Goes through
 * ChangeTenantStatus, audited with the system actor.
 */
final readonly class TenantGatewayActivation
{
    public const string REASON = 'Payment gateway connection ready (automatic, plan 21.3).';

    public function __construct(private ChangeTenantStatus $changeStatus) {}

    public function afterGatewayReady(GatewayConnection $connection): void
    {
        if (! $connection->status->canCharge()) {
            return;
        }

        $tenant = Tenant::query()->find($connection->tenant_id);

        if ($tenant === null || $tenant->status !== TenantStatus::PendingOnboarding) {
            return;
        }

        $this->changeStatus->handleAsSystem(
            $tenant,
            new ChangeTenantStatusData(TenantStatus::Active, self::REASON),
            expectedFrom: TenantStatus::PendingOnboarding,
        );
    }
}
