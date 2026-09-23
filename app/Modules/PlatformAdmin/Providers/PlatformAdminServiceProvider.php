<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Providers;

use App\Modules\PlatformAdmin\Console\CreatePlatformAdminCommand;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\PlatformAdmin\Policies\PlatformAdminPolicy;
use App\Modules\PlatformAdmin\Policies\TenantPolicy;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PlatformAdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(PlatformAdmin::class, PlatformAdminPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformAdminCommand::class]);
        }
    }
}
