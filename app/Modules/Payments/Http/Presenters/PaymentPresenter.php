<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Presenters;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\Shared\Time\IsoDateTime;
use Carbon\CarbonImmutable;

/**
 * The `payment` object of the public API (plan 10.6): the same object the
 * events carry (PaymentSnapshot) plus what only a read shows, the card's
 * brand and country and the authorization and cancellation times. Our
 * identifiers only: no gateway ID, no last four digits, no fingerprint and no
 * payer data (ADR-019, ADR-0058). The payer's data and the card number never
 * leave the checkout.
 */
final class PaymentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toApi(PaymentAttempt $attempt, PaymentLink $link): array
    {
        return [
            ...PaymentSnapshot::of($attempt, $link),
            'card' => [
                'brand' => $attempt->card_brand,
                'country' => $attempt->card_country,
            ],
            'authorized_at' => self::time($attempt->authorized_at),
            'canceled_at' => self::time($attempt->canceled_at),
        ];
    }

    private static function time(?CarbonImmutable $time): ?string
    {
        return $time !== null ? IsoDateTime::format($time) : null;
    }
}
