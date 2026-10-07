<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use Carbon\CarbonImmutable;

/**
 * Filters and cursor of `GET /v1/payments` (plan 10.1, 10.6). Cursors are
 * bare ULIDs of payments; the list is newest first. `paymentLinkId` is the
 * bare ULID of the link whose payments are wanted.
 */
final readonly class ListPaymentsData
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $startingAfter = null,
        public ?string $endingBefore = null,
        public ?PaymentAttemptStatus $status = null,
        public ?string $paymentLinkId = null,
        public ?CarbonImmutable $createdGte = null,
        public ?CarbonImmutable $createdLte = null,
    ) {}
}
