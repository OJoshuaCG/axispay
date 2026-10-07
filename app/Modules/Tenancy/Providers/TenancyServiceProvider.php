<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Policies\TenantPaymentSettingsPolicy;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, never singleton: reset for every request and every queued job (plan 6.1).
        $this->app->scoped(TenantContext::class);
        // Remembers tenants for one request or job only (never stale across them).
        $this->app->scoped(TenantAccess::class);
    }

    public function boot(): void
    {
        // The tenant's payment settings (conversion and link expiration, ADR-0063): the policy is asked about the typed settings document.
        Gate::policy(TenantSettings::class, TenantPaymentSettingsPolicy::class);
    }
}
