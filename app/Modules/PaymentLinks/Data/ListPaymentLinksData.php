<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Data;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Shared\Money\CurrencyCode;
use Carbon\CarbonImmutable;

/**
 * Filters and cursor of `GET /v1/payment_links` (plan 10.1, 10.5). Cursors
 * are bare ULIDs of links; the list is newest first.
 */
final readonly class ListPaymentLinksData
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $startingAfter = null,
        public ?string $endingBefore = null,
        public ?PaymentLinkStatus $status = null,
        public ?CurrencyCode $currency = null,
        public ?string $clientReferenceId = null,
        public ?CarbonImmutable $createdGte = null,
        public ?CarbonImmutable $createdLte = null,
    ) {}
}
