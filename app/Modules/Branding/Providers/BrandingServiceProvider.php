<?php

declare(strict_types=1);

namespace App\Modules\Branding\Providers;

use App\Modules\Branding\Models\PlatformFavicon;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Policies\PlatformBrandingPolicy;
use App\Modules\Branding\Services\PlatformBrand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Platform brand (ADR-0053): the logo, its display mode and the upload rules
 * shared with the tenant logos of Phase 8.
 */
final class BrandingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request (per job under Octane-less workers too): the brand
        // state is read once and remembered while a page renders.
        $this->app->scoped(PlatformBrand::class);
    }

    public function boot(): void
    {
        Gate::policy(PlatformLogo::class, PlatformBrandingPolicy::class);
        Gate::policy(PlatformFavicon::class, PlatformBrandingPolicy::class);
    }
}
