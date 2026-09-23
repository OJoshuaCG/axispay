<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs\Middleware;

use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\TenantContext;
use Closure;
use LogicException;

/**
 * Job middleware that re-establishes the tenant context captured when a
 * TenantAware job was dispatched (plan 6.3). Jobs that are not TenantAware
 * run without a context, so any tenant query inside them throws (plan 26.2
 * case 17).
 */
final class RestoreTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantAware) {
            throw new LogicException('RestoreTenantContext only applies to TenantAware jobs.');
        }

        return $this->context->runAsTenant($job->tenantId(), $job->livemode(), static fn (): mixed => $next($job));
    }
}
