<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Webhooks\Data\DeliveryOutcome;
use App\Modules\Webhooks\Enums\WebhookDeliveryError;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointChange;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use App\Modules\Webhooks\Services\RetrySchedule;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use App\Modules\Webhooks\Services\WebhookEndpointNotifier;
use App\Modules\Webhooks\Services\WebhookSender;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one pending delivery attempt and records its result (plan 15.4 -
 * 15.7), in the current tenant context:
 *
 *  1. claim: lock the row, check it is pending and due, take a send lease
 *     (commit: no transaction or lock is held during the call). A job that
 *     runs a moment early (clock skew between servers, up to
 *     EARLY_TOLERANCE_SECONDS) is sent; one that runs earlier is handed
 *     back to the queue with the remaining delay (`$releaseEarly`) instead
 *     of waiting for the sweeper;
 *  2. send it (SSRF check, signature, 10 s budget);
 *  3. record: lock the endpoint then the row; success, or failure with the
 *     next automatic attempt scheduled (plan 15.6) or the delivery
 *     abandoned; a blocked destination is never retried (plan 15.7).
 *
 * Automatic attempts track the endpoint's continuous failures: the first
 * failure after a success starts `failing_since`, a success clears it, and
 * 5 days of failures disable the endpoint (`disabled_by_failures`) and
 * e-mail the owners and the users with `webhooks:manage`. Manual resends
 * and tests are a single attempt and never change the endpoint's health.
 */
final readonly class DeliverWebhook
{
    /** Seconds a job may run before its attempt is due and still send it. */
    public const int EARLY_TOLERANCE_SECONDS = 2;

    public function __construct(
        private WebhookSender $sender,
        private RetrySchedule $schedule,
        private WebhookDispatcher $dispatcher,
        private WebhookEndpointNotifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  (Closure(int): void)|null  $releaseEarly  called with the seconds left when the
     *                                                   attempt is not due yet (the queued job
     *                                                   releases itself with that delay)
     * @return WebhookDelivery|null the attempt as recorded, or null when it no longer exists
     */
    public function handle(string $deliveryId, ?Closure $releaseEarly = null): ?WebhookDelivery
    {
        $claim = $this->claim($deliveryId, $releaseEarly !== null);

        if (is_int($claim) && $releaseEarly !== null) {
            $releaseEarly($claim);

            return WebhookDelivery::query()->find($deliveryId);
        }

        if (! is_array($claim)) {
            return WebhookDelivery::query()->find($deliveryId);
        }

        [$endpoint, $event] = $claim;

        try {
            $outcome = $this->sender->send($endpoint, $event);
        } catch (Throwable $e) {
            report($e);
            $outcome = DeliveryOutcome::failed(WebhookDeliveryError::InternalError);
        }

        return $this->record($deliveryId, $outcome);
    }

    /**
     * @return array{0: WebhookEndpoint, 1: WebhookEvent}|int|null the endpoint and event to send;
     *                                                             the seconds until it is due
     *                                                             (only when $releasable); or
     *                                                             null: nothing to send
     */
    private function claim(string $deliveryId, bool $releasable): array|int|null
    {
        return DB::transaction(function () use ($deliveryId, $releasable): array|int|null {
            $delivery = WebhookDelivery::query()->lockForUpdate()->find($deliveryId);
            $now = CarbonImmutable::now();

            if ($delivery === null
                || $delivery->status !== WebhookDeliveryStatus::Pending
                || ($delivery->lease_until !== null && $delivery->lease_until->greaterThan($now))) {
                return null;
            }

            if ($delivery->scheduled_at->greaterThan($now->addSeconds(self::EARLY_TOLERANCE_SECONDS))) {
                if (! $releasable) {
                    return null;
                }

                // Handed back to the queue: the sweeper must not queue it again meanwhile.
                $delivery->forceFill(['queued_at' => $now])->save();

                return max(1, (int) ceil($now->diffInSeconds($delivery->scheduled_at)));
            }

            $endpoint = WebhookEndpoint::query()->find($delivery->webhook_endpoint_id);

            // A test is sent even to a disabled endpoint; anything else is dropped.
            if ($endpoint === null || ($delivery->trigger !== WebhookDeliveryTrigger::Test && ! $endpoint->isEnabled())) {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Abandoned,
                    'error' => WebhookDeliveryError::EndpointDisabled,
                ])->save();

                return null;
            }

            $event = WebhookEvent::query()->findOrFail($delivery->webhook_event_id);

            $delivery->forceFill([
                'lease_until' => $now->addSeconds(max(15, config()->integer('axispay.webhooks.send_lease_seconds'))),
                'sent_at' => $now,
            ])->save();

            return [$endpoint, $event];
        });
    }

    private function record(string $deliveryId, DeliveryOutcome $outcome): ?WebhookDelivery
    {
        $next = null;
        $disabled = null;

        $delivery = DB::transaction(function () use ($deliveryId, $outcome, &$next, &$disabled): ?WebhookDelivery {
            $delivery = WebhookDelivery::query()->find($deliveryId);
            $endpoint = $delivery !== null ? WebhookEndpoint::query()->lockForUpdate()->find($delivery->webhook_endpoint_id) : null;
            $delivery = $delivery !== null ? WebhookDelivery::query()->lockForUpdate()->find($deliveryId) : null;

            if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Pending) {
                return $delivery;
            }

            $now = CarbonImmutable::now();
            $automatic = $delivery->trigger === WebhookDeliveryTrigger::Automatic;

            $delivery->forceFill([
                'response_status' => $outcome->responseStatus,
                'response_body_excerpt' => $outcome->responseExcerpt,
                'duration_ms' => $outcome->durationMs,
                'error' => $outcome->error,
                'lease_until' => null,
            ]);

            if ($outcome->succeeded) {
                $delivery->status = WebhookDeliveryStatus::Succeeded;

                if ($automatic && $endpoint !== null && $endpoint->failing_since !== null) {
                    $endpoint->forceFill(['failing_since' => null])->save();
                }

                $delivery->save();

                return $delivery;
            }

            if (! $automatic || $endpoint === null) {
                $delivery->status = WebhookDeliveryStatus::Failed;
                $delivery->save();

                return $delivery;
            }

            $disabled = $this->trackFailure($endpoint, $now);
            $delay = $outcome->isRetriable() && $endpoint->isEnabled() ? $this->schedule->delayBefore($delivery->attempt_number + 1) : null;

            if ($delay === null) {
                $delivery->status = WebhookDeliveryStatus::Abandoned;
                $delivery->save();

                return $delivery;
            }

            $retryAt = $now->addSeconds($delay);
            $delivery->forceFill(['status' => WebhookDeliveryStatus::Failed, 'next_retry_at' => $retryAt])->save();

            $next = new WebhookDelivery;
            $next->forceFill([
                'tenant_id' => $delivery->tenant_id,
                'livemode' => $delivery->livemode,
                'webhook_event_id' => $delivery->webhook_event_id,
                'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
                'trigger' => WebhookDeliveryTrigger::Automatic,
                'attempt_number' => $delivery->attempt_number + 1,
                'status' => WebhookDeliveryStatus::Pending,
                'scheduled_at' => $retryAt,
            ])->save();

            // Close retries are queued now with a delay; later ones by the sweeper when due.
            if ($delay <= config()->integer('axispay.webhooks.delayed_dispatch_max_seconds')) {
                $queued = $next;
                DB::afterCommit(fn () => $this->dispatcher->dispatch($queued->id, $queued->tenant_id, $queued->livemode, $delay));
            }

            return $delivery;
        });

        if ($disabled instanceof WebhookEndpoint) {
            $this->notifier->notify($disabled, WebhookEndpointChange::DisabledByFailures);
        }

        return $delivery;
    }

    /**
     * Starts or continues the endpoint's failure streak; after the configured
     * days it disables the endpoint and returns it (plan 15.6).
     */
    private function trackFailure(WebhookEndpoint $endpoint, CarbonImmutable $now): ?WebhookEndpoint
    {
        $since = $endpoint->failing_since ?? $now;
        $changes = ['failing_since' => $since];
        $limit = $now->subDays(max(1, config()->integer('axispay.webhooks.disable_after_failing_days')));
        $disable = $endpoint->isEnabled() && $since->lessThanOrEqualTo($limit);

        if ($disable) {
            $changes += ['status' => WebhookEndpointStatus::DisabledByFailures, 'disabled_at' => $now];
        }

        $endpoint->forceFill($changes)->save();

        if (! $disable) {
            return null;
        }

        $this->audit->record(AuditAction::WebhookEndpointDisabledByFailures, $endpoint, [
            'host' => $endpoint->host(),
            'livemode' => $endpoint->livemode,
            'failing_since' => $since->toIso8601ZuluString(),
        ], actor: Actor::system());

        return $endpoint;
    }
}
