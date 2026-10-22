<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

/**
 * Pre-payment validation metrics of the current tenant and mode over a
 * window (plan 15.8.7): real calls that ended (test calls and calls still in
 * flight are left out), how many were rejected by the merchant, how many
 * failed (timeout, connection, invalid answer...) and the p95 latency.
 */
final readonly class ValidationMetrics
{
    private const int TENTHS_OF_PERCENT = 1000;

    public function __construct(
        public int $windowDays,
        public int $calls,
        public int $rejected,
        public int $failed,
        public ?int $p95LatencyMs,
    ) {}

    /** "12.5" (percent, one decimal), or null without calls. */
    public function rejectionRate(): ?string
    {
        return $this->percentOf($this->rejected);
    }

    /** "3.0" (percent, one decimal), or null without calls. */
    public function failureRate(): ?string
    {
        return $this->percentOf($this->failed);
    }

    /** Integer arithmetic rounded half up once, so no float is involved. */
    private function percentOf(int $part): ?string
    {
        if ($this->calls === 0) {
            return null;
        }

        $tenths = intdiv($part * self::TENTHS_OF_PERCENT * 2 + $this->calls, $this->calls * 2);

        return intdiv($tenths, 10).'.'.($tenths % 10);
    }
}
