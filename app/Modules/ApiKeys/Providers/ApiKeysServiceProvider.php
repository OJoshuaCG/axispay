<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Providers;

use App\Modules\ApiKeys\Console\PurgeIdempotencyRecordsCommand;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Policies\ApiKeyPolicy;
use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\ApiKeys\Services\CurrentIdempotentRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * API access layer (ADR-0048): API keys, authentication, scopes, rate
 * limiting and idempotency of the public API.
 */
final class ApiKeysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped like TenantContext: never carried from one request or job to the next.
        $this->app->scoped(CurrentApiKey::class);
        $this->app->scoped(CurrentIdempotentRequest::class);
    }

    public function boot(): void
    {
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeIdempotencyRecordsCommand::class]);
        }
    }
}
