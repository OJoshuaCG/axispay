<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Providers;

use App\Modules\Gateways\Console\CheckApiKeyConnectionsCommand;
use App\Modules\Gateways\Console\RotateGatewayCredentialsKeyCommand;
use App\Modules\Gateways\Console\SyncWebhookEndpointsCommand;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Policies\GatewayConnectionPolicy;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Gateways\Services\GatewayFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class GatewaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped like TenantContext: reset per request and per job, never a
        // cached client or merchant key across them.
        $this->app->scoped(GatewayFactory::class);
    }

    public function boot(): void
    {
        Gate::policy(GatewayConnection::class, GatewayConnectionPolicy::class);

        // ADR-0051: the checkout sandbox never runs outside local and testing.
        SandboxMode::assertSafe((string) $this->app->environment());

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckApiKeyConnectionsCommand::class,
                RotateGatewayCredentialsKeyCommand::class,
                SyncWebhookEndpointsCommand::class,
            ]);
        }
    }
}
