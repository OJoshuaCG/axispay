<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * Time left for gateway calls (ADR-0051). A call is only started when its
 * worst case (every bounded Stripe call: timeout × tries plus the SDK's
 * retry pause) still fits:
 *
 *  - a payer request answers before the web server gives up: if Stripe is
 *    slow, the payer is told the payment is processing instead of seeing an
 *    error while a charge may still be under way;
 *  - a job stops before its own time limit (forJob()), so it is never killed
 *    mid-call;
 *  - an actor holding an attempt's lease never calls past the lease
 *    (withinLease()), so no other actor takes the attempt over while a call
 *    is still out.
 *
 * What does not fit is left as it is, for the next check (a job, an event,
 * the reconciliation).
 */
final readonly class CallBudget
{
    /** Seconds a job keeps for its own work around the gateway calls. */
    private const int JOB_MARGIN_SECONDS = 10;

    /** Seconds a lease holder keeps between its last call and the lease's end. */
    private const int LEASE_MARGIN_SECONDS = 5;

    private function __construct(private float $endsAt) {}

    /** The pay request's budget, counted from now (`checkout.request_budget_seconds`). */
    public static function forPayerRequest(): self
    {
        return new self(self::now() + max(1, config()->integer('axispay.checkout.request_budget_seconds')));
    }

    /**
     * A queued job's budget: its `$timeout` minus a margin for the rest of
     * its work, so its gateway calls always end before the worker kills it.
     */
    public static function forJob(int $timeoutSeconds): self
    {
        return new self(self::now() + self::jobSeconds($timeoutSeconds));
    }

    /** Seconds of gateway time a job with this `$timeout` may spend (forJob()). */
    public static function jobSeconds(int $timeoutSeconds): int
    {
        return max(0, $timeoutSeconds - self::JOB_MARGIN_SECONDS);
    }

    /**
     * The same budget, but ending when a lease taken or renewed just now
     * ends (with a margin): the holder never calls the gateway after it may
     * have lost the attempt. `$budget` null: only the lease bounds it.
     */
    public static function withinLease(?self $budget): self
    {
        $leaseEnds = self::now() + max(1, config()->integer('axispay.checkout.confirmation_lease_seconds')) - self::LEASE_MARGIN_SECONDS;

        return new self(min($budget->endsAt ?? INF, $leaseEnds));
    }

    /** No limit of its own (a caller's lease or job budget still applies through withinLease()). */
    public static function unlimited(): self
    {
        return new self(INF);
    }

    /**
     * Whether `$calls` more worst-case gateway calls, plus `$extraSeconds`,
     * still fit. Depends on the clock: two calls may answer differently.
     *
     * @phpstan-impure
     */
    public function affords(int $calls = 1, int $extraSeconds = 0): bool
    {
        return self::now() + $calls * self::worstCallSeconds() + $extraSeconds <= $this->endsAt;
    }

    /** Worst case of one bounded Stripe call: every try timing out, plus the SDK's pause between tries (at most 2 s). */
    public static function worstCallSeconds(): int
    {
        $tries = 1 + max(0, config()->integer('services.stripe.max_network_retries'));

        return $tries * max(1, config()->integer('services.stripe.timeout_seconds')) + ($tries - 1) * 2;
    }

    private static function now(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }
}
