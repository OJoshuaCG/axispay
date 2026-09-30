<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Jobs;

use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\Middleware\RestoreTenantContext;
use App\Modules\Webhooks\Actions\DeliverWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one pending webhook delivery (plan 15.4 step 2, 15.6) in its
 * tenant's context. Only identifiers are serialized, never the secret or
 * the body. Retries are not queue retries: each one is a new delivery row
 * scheduled by DeliverWebhook, so a single try is enough here; a job lost or
 * killed is queued again by the sweeper, and the send lease keeps a
 * duplicated job from sending twice. A job that runs before its attempt is
 * due releases itself with the remaining delay: up to `$tries` runs absorb
 * that, while any exception still fails it at once (`$maxExceptions`).
 */
final class DeliverWebhookJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Worst case about 40 s: the 10-second budget (DNS lookup included) plus
     * one DNS lookup that overran it (bounded only by the system resolver,
     * about 30 s with its defaults) plus the bookkeeping. Below the send
     * lease (60 s), so a duplicated job never sends in parallel, and far
     * below retry_after (150 s).
     */
    public int $timeout = 55;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $webhookDeliveryId,
        public readonly string $capturedTenantId,
        public readonly bool $capturedLivemode,
    ) {}

    public function tenantId(): string
    {
        return $this->capturedTenantId;
    }

    public function livemode(): bool
    {
        return $this->capturedLivemode;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(RestoreTenantContext::class)];
    }

    public function handle(DeliverWebhook $deliver): void
    {
        $deliver->handle($this->webhookDeliveryId, fn (int $seconds) => $this->release($seconds));
    }
}
