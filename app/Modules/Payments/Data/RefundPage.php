<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Models\Refund;

/** One page of `GET /v1/refunds`: the refunds in the order shown, and whether more exist in the direction paged. */
final readonly class RefundPage
{
    /**
     * @param  list<Refund>  $refunds
     */
    public function __construct(
        public array $refunds,
        public bool $hasMore,
    ) {}
}
