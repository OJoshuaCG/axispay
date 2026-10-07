<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Jobs;

use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Data\ValidationTimeouts;
use App\Modules\ProviderEvents\Actions\ProcessProviderEvent;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\Middleware\RestoreTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Processes one stored gateway event (plan 14.2 steps 5-8) on the `critical`
 * queue, in the tenant context of its connection (routing already happened
 * when it was stored). Only IDs are serialized. Five attempts with backoff,
 * then the event is marked `failed` (and logged for alerting).
 *
 * Unique per stored event for as long as all its tries can take (ADR-0051):
 * a duplicate delivery, the sweeper or an operator retry never queues a
 * second job while one is queued, running or waiting for its next try.
 */
final class ProcessProviderEventJob implements ShouldBeUnique, ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Below the queue's retry_after: two bounded Stripe calls (42 s each)
     * plus the merchant validation (ADR-0051). Derived from the validation
     * timeout when the job is created (ADR-0061): 115 s at 5 s.
     */
    public int $timeout;

    public int $tries = 5;

    /** Every try's time limit plus every backoff, with a margin (see backoff()): 1500 s at a 5 s validation timeout. */
    public int $uniqueFor;

    public function __construct(
        public readonly string $providerEventId,
        public readonly string $capturedTenantId,
        public readonly bool $capturedLivemode,
    ) {
        $timeouts = ValidationTimeouts::current();
        $this->timeout = $timeouts->jobTimeoutSeconds();
        $this->uniqueFor = $timeouts->providerEventUniqueForSeconds();
        $this->onQueue('critical');
    }

    public function uniqueId(): string
    {
        return $this->providerEventId;
    }

    /**
     * Queues the job unless one for this stored event is already queued,
     * running or waiting for its next try. The unique lock is taken once,
     * here, and handed to the queued job (which releases it at the end),
     * so there is no moment in which a concurrent dispatch could be lost.
     *
     * @return bool whether a job was queued
     */
    public static function dispatchIfIdle(string $providerEventId, string $tenantId, bool $livemode): bool
    {
        $job = new self($providerEventId, $tenantId, $livemode);

        if (! (new UniqueLock(Cache::driver()))->acquire($job)) {
            return false;
        }

        app(Dispatcher::class)->dispatch($job);

        return true;
    }

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

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(ProcessProviderEvent $process): void
    {
        try {
            // Its gateway calls end before the job's own time limit (ADR-0051).
            $process->handle($this->providerEventId, CallBudget::forJob($this->timeout));
        } catch (GatewayAuthenticationException|GatewayRequestException $e) {
            // Plan 12.6: refused credentials or a 4xx never succeed on retry: fail now.
            $this->fail($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessProviderEvent::class)->markFailed($this->providerEventId, $this->capturedTenantId, $this->capturedLivemode, $exception);
    }
}
