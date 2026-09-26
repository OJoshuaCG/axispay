<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Listeners;

use App\Modules\Gateways\Events\GatewayConnectionDisconnected;
use App\Modules\PaymentLinks\Jobs\CancelLinksOfDisconnectedGatewayJob;
use App\Modules\Tenancy\TenantContext;

/**
 * Plan 12.3.4: a disconnection queues the cancellation of the tenant's
 * active links in that mode (CancelLinksOfDisconnectedGatewayJob), so a
 * failure halfway is retried by the queue instead of failing the
 * disconnection. `axispay:payment-links:reconcile-gateways` catches anything
 * a lost job left behind.
 */
final readonly class CancelLinksOfDisconnectedGateway
{
    public function __construct(private TenantContext $context) {}

    public function handle(GatewayConnectionDisconnected $event): void
    {
        $this->context->runAsTenant($event->tenantId, $event->livemode, static function (): void {
            CancelLinksOfDisconnectedGatewayJob::dispatch();
        });
    }
}
