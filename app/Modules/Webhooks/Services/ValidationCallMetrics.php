<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Data\ValidationMetrics;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Models\ValidationCall;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Plan 15.8.7: per-tenant rejection rate, failure rate and p95 latency of
 * the pre-payment validation, read from `validation_calls` in the current
 * tenant and mode (tenant scope). The window is the calls' retention
 * (30 days), the same rows the "Recent calls" table shows.
 */
final class ValidationCallMetrics
{
    private const int PERCENTILE = 95;

    public function current(): ValidationMetrics
    {
        $windowDays = max(1, config()->integer('axispay.pre_payment_validation.retention_days'));
        $since = now()->subDays($windowDays);

        $calls = $this->endedRealCalls($since)->count();
        $rejected = $this->endedRealCalls($since)->where('outcome', ValidationCallOutcome::Rejected->value)->count();
        $failed = $this->endedRealCalls($since)->where('outcome', ValidationCallOutcome::Failed->value)->count();

        return new ValidationMetrics($windowDays, $calls, $rejected, $failed, $this->p95LatencyMs($since));
    }

    /**
     * Test calls ("Test validation") are not traffic, and a call without an
     * outcome is still waiting for the merchant.
     *
     * @return Builder<ValidationCall>
     */
    private function endedRealCalls(CarbonInterface $since): Builder
    {
        return ValidationCall::query()
            ->where('is_test', false)
            ->whereNotNull('outcome')
            ->where('created_at', '>=', $since);
    }

    /**
     * Nearest-rank percentile: the value at position ceil(0.95 * n) of the
     * latencies in ascending order, read with one ordered OFFSET query.
     */
    private function p95LatencyMs(CarbonInterface $since): ?int
    {
        $timedCalls = $this->endedRealCalls($since)->whereNotNull('duration_ms')->count();

        if ($timedCalls === 0) {
            return null;
        }

        $rank = intdiv($timedCalls * self::PERCENTILE + 99, 100);
        $latency = $this->endedRealCalls($since)
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->offset($rank - 1)
            ->limit(1)
            ->value('duration_ms');

        return is_numeric($latency) ? (int) $latency : null;
    }
}
