<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Jobs\Middleware\RestoreTenantContext;
use App\Modules\Tenancy\TenantContext;

/**
 * Helper for TenantAware jobs: call captureTenantContext() in the constructor
 * (at dispatch time) and the job restores that context before handle().
 *
 * Only identifiers are serialized, never models or secrets.
 */
trait CapturesTenantContext
{
    public string $capturedTenantId;

    public bool $capturedLivemode;

    protected function captureTenantContext(): void
    {
        $context = app(TenantContext::class);

        $this->capturedTenantId = $context->idOrFail(static::class);
        $this->capturedLivemode = $context->livemode();
    }

    public function tenantId(): string
    {
        return $this->capturedTenantId;
    }

    public function livemode(): bool
    {
        return $this->capturedLivemode;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(RestoreTenantContext::class)];
    }
}
