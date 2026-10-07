<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Models\PaymentAttempt;

/**
 * One page of `GET /v1/payments`: the payments (each with its link loaded)
 * in the order shown, and whether more exist in the direction paged.
 */
final readonly class PaymentPage
{
    /**
     * @param  list<PaymentAttempt>  $payments
     */
    public function __construct(
        public array $payments,
        public bool $hasMore,
    ) {}
}
