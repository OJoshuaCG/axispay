<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Shared\Money\Money;

/**
 * A validated refund request (plan 10.7): the payment (its bare ULID), the
 * amount in the currency charged (null: everything still refundable) and the
 * reason.
 */
final readonly class CreateRefundData
{
    public function __construct(
        public string $attemptId,
        public ?Money $amount,
        public RefundReason $reason,
    ) {}
}
