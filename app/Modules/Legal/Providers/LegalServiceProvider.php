<?php

declare(strict_types=1);

namespace App\Modules\Legal\Providers;

use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\Legal\Policies\PlatformLegalDocumentPolicy;
use App\Modules\Legal\Policies\TenantLegalDocumentPolicy;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\Legal\Services\TenantLegalDocuments;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Legal texts (ADR-0056): the merchant's privacy notice and terms, and the
 * platform's own two.
 */
final class LegalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request (or job): documents are read once while a page renders.
        $this->app->scoped(TenantLegalDocuments::class);
        $this->app->scoped(PlatformLegalDocuments::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantLegalDocument::class, TenantLegalDocumentPolicy::class);
        Gate::policy(PlatformLegalDocument::class, PlatformLegalDocumentPolicy::class);
    }
}
