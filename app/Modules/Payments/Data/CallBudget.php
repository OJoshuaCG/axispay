<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * Time left for gateway calls in one payer request (ADR-0051). A call is
 * only started when its worst case (every bounded Stripe call: timeout ×
 * tries plus the SDK's retry pause) still fits, so the request always
 * answers before the web server gives up: if Stripe is slow, the payer is
 * told the payment is processing instead of seeing an error while a charge
 * may still be under way.
 */
final readonly class CallBudget
{
    private function __construct(private float $endsAt) {}

    /** The pay request's budget, counted from now (`checkout.request_budget_seconds`). */
    public static function forPayerRequest(): self
    {
        return new self(self::now() + max(1, config()->integer('axispay.checkout.request_budget_seconds')));
    }

    /** No limit (jobs, the reconciliation: their own time limits apply). */
    public static function unlimited(): self
    {
        return new self(INF);
    }

    /** Whether `$calls` more worst-case gateway calls, plus `$extraSeconds`, still fit. */
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
