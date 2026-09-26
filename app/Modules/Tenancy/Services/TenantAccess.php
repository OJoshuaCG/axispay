<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Enums\ApiAccess;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The single place that turns a tenant's status into what its users and API
 * keys may do (plan 21.3, ADR-013, ADR-0048):
 *
 *  - panel: `suspended` and `closed` tenants are read-only;
 *  - API: a `closed` tenant is read-only for `axispay.api.closed_tenant_read_days`
 *    days after closing, then its keys stop working; every other status has
 *    full access (resource rules, such as "suspended cannot create links",
 *    apply on top).
 *
 * Scoped (one instance per request or job): each tenant is read once and
 * remembered, so a list whose rows each ask for permissions does not reload
 * the tenant per row. Code that must see a status changed a moment ago in
 * another request reads the tenant itself (CreatePaymentLink does, right
 * before inserting).
 */
final class TenantAccess
{
    /** @var array<string, Tenant|null> */
    private array $tenants = [];

    public function panelWritable(string $tenantId): bool
    {
        $status = $this->find($tenantId)?->status;

        return $status !== null && ! $status->isPanelReadOnly();
    }

    public function apiAccess(string $tenantId): ApiAccess
    {
        $tenant = $this->find($tenantId);

        if ($tenant === null) {
            return ApiAccess::None;
        }

        if ($tenant->status !== TenantStatus::Closed) {
            return ApiAccess::Full;
        }

        $closedAt = $tenant->closed_at ?? $tenant->status_changed_at;

        if ($closedAt === null || $closedAt->addDays(config()->integer('axispay.api.closed_tenant_read_days'))->isPast()) {
            return ApiAccess::None;
        }

        return ApiAccess::ReadOnly;
    }

    public function settings(string $tenantId): TenantSettings
    {
        return $this->find($tenantId)?->settings() ?? TenantSettings::defaults();
    }

    /** IANA time zone the tenant's people read dates in (plan 7.1; default America/Mexico_City). */
    public function timezone(string $tenantId): string
    {
        return $this->find($tenantId)->timezone ?? 'America/Mexico_City';
    }

    /** Drops what this request remembers about a tenant (its status just changed). */
    public function forget(string $tenantId): void
    {
        unset($this->tenants[$tenantId]);
    }

    private function find(string $tenantId): ?Tenant
    {
        if (! array_key_exists($tenantId, $this->tenants)) {
            $this->tenants[$tenantId] = Tenant::query()->find($tenantId);
        }

        return $this->tenants[$tenantId];
    }
}
