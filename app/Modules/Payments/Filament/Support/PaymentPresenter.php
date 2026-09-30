<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Support;

use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptDisplay;
use App\Support\Filament\PanelDefaults;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Small display helpers of the payments screens (tenant panel).
 */
final class PaymentPresenter
{
    private function __construct() {}

    /** `Visa •••• 4242`, or null when no card was seen yet. */
    public static function card(PaymentAttempt $attempt): ?string
    {
        if ($attempt->card_last4 === null) {
            return null;
        }

        $line = __('payments.attempts.card_value', ['brand' => AttemptDisplay::brandName($attempt->card_brand), 'last4' => $attempt->card_last4]);

        return is_string($line) ? $line : $attempt->card_last4;
    }

    /** A date in the panel's time zone and format. */
    public static function date(CarbonImmutable $time): string
    {
        return $time->setTimezone(FilamentTimezone::get())->translatedFormat(PanelDefaults::DATE_TIME_FORMAT);
    }
}
