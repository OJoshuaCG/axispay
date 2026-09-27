<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Listeners;

use App\Modules\PaymentLinks\Jobs\CancelLinksOfClosedTenantJob;
use App\Modules\Tenancy\Events\TenantClosed;
use App\Modules\Tenancy\TenantContext;

/**
 * Plan 21.3: closing a tenant queues the cancellation of its active links,
 * one job per mode (test and live links are separate).
 */
final readonly class CancelLinksOfClosedTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(TenantClosed $event): void
    {
        foreach ([false, true] as $livemode) {
            $this->context->runAsTenant($event->tenantId, $livemode, static function (): void {
                CancelLinksOfClosedTenantJob::dispatch();
            });
        }
    }
}
