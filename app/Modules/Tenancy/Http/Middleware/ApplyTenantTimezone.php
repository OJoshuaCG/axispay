<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant panel (ADR-0049): dates and times are shown in the tenant's time
 * zone (`tenants.timezone`). Runs after ResolveTenantContext. Storage stays
 * UTC (plan 6.8); only the display changes. An unknown zone falls back to
 * UTC display rather than failing the page.
 */
final readonly class ApplyTenantTimezone
{
    public function __construct(
        private TenantContext $context,
        private TenantAccess $tenants,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->context->idOrNull();

        if ($tenantId !== null) {
            $timezone = $this->tenants->timezone($tenantId);
            FilamentTimezone::set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC');
        }

        return $next($request);
    }
}
