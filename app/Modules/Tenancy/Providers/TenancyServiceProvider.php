<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, never singleton: reset for every request and every queued job (plan 6.1).
        $this->app->scoped(TenantContext::class);
    }
}
