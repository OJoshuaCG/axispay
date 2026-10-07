<?php

declare(strict_types=1);

namespace App\Modules\Fx\Data;

use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A conversion worked out but not stored yet (FxQuoter::estimate): what the
 * checkout page shows as the legend, what link creation checks against the
 * MXN minimum, and what FxQuoter::issue() writes as an immutable quote.
 */
final readonly class FxConversion
{
    public function __construct(
        public string $source,
        public ExchangeRate $rate,
        public ?string $rateDate,
        public ?string $exchangeRateId,
        public int $markupBps,
        public ExchangeRate $effectiveRate,
        public Money $original,
        public Money $converted,
        public CarbonImmutable $expiresAt,
    ) {}
}
