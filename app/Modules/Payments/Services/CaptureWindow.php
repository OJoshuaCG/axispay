<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentAttempt;
use Carbon\CarbonImmutable;

/**
 * The one rule for late authorizations (ADR-0051): an authorization is
 * captured only within `axispay.checkout.capture_window_minutes` of the
 * moment it was authorized. After that, whoever sees it (the checkout, a
 * webhook, the reconciliation) voids it; nobody captures it late. The only
 * exception is a payment the gateway already reports as succeeded: the
 * payment wins.
 */
final class CaptureWindow
{
    public static function elapsed(PaymentAttempt $attempt): bool
    {
        $authorizedAt = $attempt->authorized_at ?? $attempt->updated_at ?? CarbonImmutable::now();

        return $authorizedAt->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes(max(1, config()->integer('axispay.checkout.capture_window_minutes'))));
    }
}
