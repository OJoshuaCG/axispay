<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\RefundOrigin;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund of a captured payment (plan 7.5, 16.1), in the currency charged.
 * `status` changes only through ApplyProviderRefund. Exposed as `re_<ULID>`;
 * the gateway's refund ID never leaves the panel (ADR-019).
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $payment_attempt_id
 * @property string|null $provider_refund_id
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property RefundState $status
 * @property RefundReason $reason
 * @property string|null $failure_reason a generic code, never the gateway's own reason
 * @property RefundOrigin $origin
 * @property string $created_by_actor_type
 * @property string|null $created_by_actor_id
 * @property string|null $idempotency_key
 * @property string|null $idempotency_request_hash
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Refund extends Model
{
    use BelongsToMode;
    use BelongsToTenant;

    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    use HasPrefixedId;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $attributes = [
        'status' => 'pending',
        'reason' => 'other',
        'origin' => 'api',
    ];

    public static function resourceType(): ResourceType
    {
        return ResourceType::Refund;
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * @return BelongsTo<PaymentAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    protected static function newFactory(): RefundFactory
    {
        return RefundFactory::new();
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'status' => RefundState::class,
            'reason' => RefundReason::class,
            'origin' => RefundOrigin::class,
            'succeeded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
