<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\Fx\Models\StoredExchangeRate;
use App\Modules\Fx\Notifications\FxRateAlertNotification;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The alerts of the FX rate pipeline (plan 13.3), sent to every platform
 * superadmin: a FIX held back for review, and a stale FIX. The stale alert
 * is sent at most once a day (the job runs several times a day).
 */
final class FxRateAlerts
{
    private const int STALE_ALERT_TTL_SECONDS = 86_400;

    public function requiresReview(StoredExchangeRate $held): void
    {
        Log::warning('A Banxico FIX was held back for review: it differs too much from the previous one.', ['rate_date' => $held->rate_date]);

        $this->send(new FxRateAlertNotification(FxRateAlertNotification::REQUIRES_REVIEW, $held->rate_date, $held->rate));
    }

    public function stale(StoredExchangeRate $latest): void
    {
        if (! Cache::add('axispay:fx:stale-alert:'.now()->toDateString(), true, self::STALE_ALERT_TTL_SECONDS)) {
            return;
        }

        Log::warning('The newest Banxico FIX is stale: banxico_fix conversions are blocked.', ['rate_date' => $latest->rate_date]);

        $this->send(new FxRateAlertNotification(FxRateAlertNotification::STALE, $latest->rate_date, $latest->rate));
    }

    private function send(FxRateAlertNotification $notification): void
    {
        Notification::send(PlatformAdmin::query()->get(), $notification);
    }
}
