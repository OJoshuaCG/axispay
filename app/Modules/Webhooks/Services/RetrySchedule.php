<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

/**
 * The automatic delivery schedule of plan 15.6 (config
 * `axispay.webhooks.retry_schedule_seconds`): entry N is the wait before
 * attempt N+1, counted from the previous failure. Default: immediate, 5 s,
 * 5 min, 30 min, 2 h, 5 h, 10 h, 10 h (8 attempts, about 27 hours).
 */
final class RetrySchedule
{
    public function maxAttempts(): int
    {
        return count($this->delays());
    }

    /**
     * Seconds to wait before `$attempt` (1-based), or null when the
     * schedule has no such attempt (the delivery is abandoned).
     */
    public function delayBefore(int $attempt): ?int
    {
        return $this->delays()[$attempt - 1] ?? null;
    }

    /**
     * Seconds from the first attempt to the last one.
     */
    public function totalSeconds(): int
    {
        return array_sum($this->delays());
    }

    /**
     * @return list<int>
     */
    private function delays(): array
    {
        $delays = [];

        foreach (config()->array('axispay.webhooks.retry_schedule_seconds') as $delay) {
            $delays[] = max(0, is_numeric($delay) ? (int) $delay : 0);
        }

        return $delays === [] ? [0] : $delays;
    }
}
