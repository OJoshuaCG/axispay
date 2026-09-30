<?php

declare(strict_types=1);

use App\Modules\Webhooks\Services\RetrySchedule;

/**
 * Plan 15.6: immediate, 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 10 h.
 */
it('follows the plan\'s schedule: 8 attempts in about 27 hours', function (): void {
    $schedule = app(RetrySchedule::class);

    expect($schedule->maxAttempts())->toBe(8)
        ->and(array_map($schedule->delayBefore(...), range(1, 8)))->toBe([0, 5, 300, 1800, 7200, 18000, 36000, 36000])
        ->and($schedule->delayBefore(9))->toBeNull()
        ->and($schedule->totalSeconds())->toBeGreaterThanOrEqual(27 * 3600)
        ->and($schedule->totalSeconds())->toBeLessThan(28 * 3600);
});

it('reads the schedule from the configuration', function (): void {
    config(['axispay.webhooks.retry_schedule_seconds' => [0, 60]]);
    $schedule = app(RetrySchedule::class);

    expect($schedule->maxAttempts())->toBe(2)
        ->and($schedule->delayBefore(2))->toBe(60)
        ->and($schedule->delayBefore(3))->toBeNull();
});
