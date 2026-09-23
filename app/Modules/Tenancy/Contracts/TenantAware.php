<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Contracts;

/**
 * A queued job that works on tenant data (plan 6.3). The RestoreTenantContext
 * job middleware re-establishes the context from these values before handle();
 * the job must list that middleware (the CapturesTenantContext trait does it).
 */
interface TenantAware
{
    public function tenantId(): string;

    public function livemode(): bool;
}
