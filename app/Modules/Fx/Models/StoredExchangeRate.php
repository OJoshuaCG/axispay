<?php

declare(strict_types=1);

namespace App\Modules\Fx\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One published exchange rate (plan 7.1 `exchange_rates`): the FIX of a day,
 * stored by FetchBanxicoFixJob. Platform-level, no tenant. `rate_date` is
 * the publication date as `Y-m-d`; `requires_review` marks a FIX that moved
 * more than the sanity limit and must not be charged (plan 13.3).
 *
 * @property string $id
 * @property string $source
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate DECIMAL(18,6)
 * @property string $rate_date Y-m-d
 * @property CarbonImmutable $fetched_at
 * @property array<mixed>|null $raw_payload
 * @property bool $requires_review
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class StoredExchangeRate extends Model
{
    use HasUlidPrimaryKey;

    public const string SOURCE_BANXICO_FIX = 'banxico_fix';

    protected $table = 'exchange_rates';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'fetched_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'requires_review' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
