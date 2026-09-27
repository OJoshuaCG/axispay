<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Providers;

use App\Modules\Gateways\Events\GatewayConnectionDisconnected;
use App\Modules\PaymentLinks\Console\ReconcileGatewayLinksCommand;
use App\Modules\PaymentLinks\Listeners\CancelLinksOfClosedTenant;
use App\Modules\PaymentLinks\Listeners\CancelLinksOfDisconnectedGateway;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Policies\PaymentLinkPolicy;
use App\Modules\Tenancy\Events\TenantClosed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PaymentLinksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(PaymentLink::class, PaymentLinkPolicy::class);

        // Plan 12.3.4: a disconnected gateway cancels the active links of its mode.
        Event::listen(GatewayConnectionDisconnected::class, CancelLinksOfDisconnectedGateway::class);

        // Plan 21.3: a closed tenant's active links are canceled.
        Event::listen(TenantClosed::class, CancelLinksOfClosedTenant::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileGatewayLinksCommand::class]);
        }
    }
}
