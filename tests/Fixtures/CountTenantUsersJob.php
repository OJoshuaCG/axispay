<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Test-only TenantAware job: counts the users it can see in its restored
 * tenant context.
 */
final class CountTenantUsersJob implements ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Queueable;

    public function __construct(public readonly string $cacheKey)
    {
        $this->captureTenantContext();
    }

    public function handle(): void
    {
        Cache::put($this->cacheKey, User::query()->count());
    }
}
