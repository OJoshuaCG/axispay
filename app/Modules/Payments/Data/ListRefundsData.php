<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\RefundState;

/**
 * Filters and cursor of `GET /v1/refunds` (plan 10.7). Cursors are bare ULIDs
 * of refunds; the list is newest first. `paymentId` is the bare ULID of the
 * payment whose refunds are wanted.
 */
final readonly class ListRefundsData
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $startingAfter = null,
        public ?string $endingBefore = null,
        public ?string $paymentId = null,
        public ?RefundState $status = null,
    ) {}
}
