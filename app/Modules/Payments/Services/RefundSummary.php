<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Enums\RefundStatus;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * `none` → `partial` → `full` for a payment (plan 16.1), from the amount
 * already refunded (`amount_refunded_minor`, kept equal to the sum of its
 * succeeded refunds by ApplyProviderRefund). A refund that is still pending
 * does not count: the money has not gone back yet.
 */
final class RefundSummary
{
    private function __construct() {}

    public static function of(PaymentAttempt $attempt): RefundStatus
    {
        return match (true) {
            $attempt->amount_refunded_minor <= 0 => RefundStatus::None,
            $attempt->amount_refunded_minor >= $attempt->amount_minor => RefundStatus::Full,
            default => RefundStatus::Partial,
        };
    }
}
