<?php

declare(strict_types=1);

namespace App\Modules\Fx\Data;

use App\Modules\Shared\Money\ExchangeRate;

/**
 * The FIX published by Banxico for one date, as read from the SIE API.
 *
 * @phpstan-type RawPayload array<mixed>
 */
final readonly class BanxicoFix
{
    /**
     * @param  string  $date  publication date, `Y-m-d`
     * @param  array<mixed>  $rawPayload  the API answer, kept with the rate for audits
     */
    public function __construct(
        public string $date,
        public ExchangeRate $rate,
        public array $rawPayload,
    ) {}
}
