<?php

declare(strict_types=1);

namespace App\Modules\Fx\Jobs;

use App\Modules\Fx\Actions\StoreBanxicoFix;
use App\Modules\Fx\Services\BanxicoClient;
use App\Modules\Fx\Services\FxRateAlerts;
use App\Modules\Fx\Services\FxRates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fetches the Banxico FIX and stores it (plan 13.3, ADR-0063). Scheduled on
 * weekdays at 12:30, 13:30 and 17:00 Mexico City time (the FIX appears
 * around noon) with a fallback at 09:00 the next day (routes/console.php).
 * A platform-level job, so not TenantAware.
 *
 * Without BANXICO_SIE_TOKEN it does nothing and never calls Banxico. An
 * error answer fails the job, which is retried with backoff (plan 13.3: short
 * timeouts, retries). After every run it checks the newest usable FIX: when
 * it is stale (more than 4 calendar days) the superadmins are alerted, since
 * `banxico_fix` conversions are blocked until a fresh one arrives.
 */
final class FetchBanxicoFixJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds between retries */
    public array $backoff = [30, 120];

    public int $timeout = 30;

    public function handle(StoreBanxicoFix $store, BanxicoClient $banxico, FxRates $rates, FxRateAlerts $alerts): void
    {
        if (! $banxico->configured()) {
            return;
        }

        $fix = $banxico->fetchLatest();

        if ($fix !== null) {
            $store->handle($fix);
        }

        $latest = $rates->latestFix();

        if ($latest !== null && $rates->isStale($latest)) {
            $alerts->stale($latest);
        }
    }
}
