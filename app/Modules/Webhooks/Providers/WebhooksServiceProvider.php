<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Providers;

use App\Modules\Webhooks\Console\PurgeValidationCallsCommand;
use App\Modules\Webhooks\Console\SweepWebhookOutboxCommand;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Policies\ValidationEndpointPolicy;
use App\Modules\Webhooks\Policies\WebhookDeliveryPolicy;
use App\Modules\Webhooks\Policies\WebhookEndpointPolicy;
use App\Modules\Webhooks\Services\DnsHostResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Outgoing webhooks (plan 15.1-15.7, ADR-0008, ADR-0057): endpoints, the
 * outbox and its sweeper, signed delivery with retries and SSRF protection;
 * and the pre-payment validation endpoints (plan 15.8, ADR-0058; the
 * validator itself is bound by PaymentsServiceProvider).
 */
final class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
        Gate::policy(WebhookDelivery::class, WebhookDeliveryPolicy::class);
        Gate::policy(ValidationEndpoint::class, ValidationEndpointPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SweepWebhookOutboxCommand::class, PurgeValidationCallsCommand::class]);
        }
    }
}
