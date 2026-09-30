<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Carbon\CarbonImmutable;

/**
 * Queues the job of a pending delivery (plan 15.4 step 2), after the commit
 * that created it, and notes when it did so the sweeper can tell a lost job
 * from one still waiting in the queue. Only identifiers travel in the job.
 */
final readonly class WebhookDispatcher
{
    public function __construct(private TenantContext $context) {}

    public function dispatch(string $deliveryId, string $tenantId, bool $livemode, int $delaySeconds = 0): void
    {
        $this->context->runAsTenant($tenantId, $livemode, static function () use ($deliveryId): void {
            WebhookDelivery::query()->whereKey($deliveryId)->update(['queued_at' => CarbonImmutable::now()]);
        });

        $pending = DeliverWebhookJob::dispatch($deliveryId, $tenantId, $livemode);

        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    /**
     * @param  iterable<WebhookDelivery>  $deliveries
     */
    public function dispatchAll(iterable $deliveries): void
    {
        foreach ($deliveries as $delivery) {
            $this->dispatch($delivery->id, $delivery->tenant_id, $delivery->livemode);
        }
    }
}
