<?php

declare(strict_types=1);

namespace App\Modules\Fx\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The conversion offered to a payer and, once an attempt points at it, the
 * conversion that was charged (plan 7.5 `fx_quotes`, 13.4, ADR-0063).
 * Immutable: it is written once and neither updated nor deleted, so the
 * amount a payer confirmed can always be reproduced from `effective_rate`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $payment_link_id
 * @property string $source `banxico_fix` | `fixed`
 * @property string|null $exchange_rate_id
 * @property string $rate DECIMAL(18,6)
 * @property string|null $rate_date Y-m-d
 * @property int $markup_bps
 * @property string $effective_rate DECIMAL(18,6)
 * @property int $original_amount_minor
 * @property CurrencyCode $original_currency
 * @property int $converted_amount_minor
 * @property CurrencyCode $converted_currency
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 */
final class FxQuote extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    public const string SOURCE_FIXED = 'fixed';

    public const string SOURCE_BANXICO_FIX = 'banxico_fix';

    /** Only the creation time exists: a quote is never updated. */
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('An FX quote is immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('An FX quote is immutable.');
        });
    }

    public function original(): Money
    {
        return Money::ofMinor($this->original_amount_minor, $this->original_currency);
    }

    public function converted(): Money
    {
        return Money::ofMinor($this->converted_amount_minor, $this->converted_currency);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'markup_bps' => 'integer',
            'rate' => 'decimal:6',
            'effective_rate' => 'decimal:6',
            'original_amount_minor' => 'integer',
            'original_currency' => CurrencyCode::class,
            'converted_amount_minor' => 'integer',
            'converted_currency' => CurrencyCode::class,
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
