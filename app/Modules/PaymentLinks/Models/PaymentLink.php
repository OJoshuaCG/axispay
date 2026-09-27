<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Models;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Enums\DisputeStatus;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Enums\RefundStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Database\AsJsonObject;
use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single-use, reopenable payment link (plan 7.5, ADR-006). Created by
 * CreatePaymentLink; `status` changes only through PaymentLinkStateMachine.
 * Exposed in the API as `plink_<ULID>`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $public_token
 * @property PaymentLinkStatus $status
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property string $description
 * @property array<array-key, string>|null $metadata keys are strings, even numeric-looking ones
 * @property string|null $client_reference_id
 * @property FxMode $fx_mode
 * @property string|null $fx_fixed_rate
 * @property array<string, string> $payer_fields_config
 * @property string|null $return_url
 * @property bool $pre_payment_validation
 * @property string $locale
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $canceled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $first_opened_at
 * @property CarbonImmutable|null $last_opened_at
 * @property int $open_count
 * @property CarbonImmutable|null $checkout_blocked_until
 * @property string|null $checkout_block_reason
 * @property CarbonImmutable|null $checkout_unblocked_at
 * @property CarbonImmutable|null $opened_event_at
 * @property RefundStatus $refund_status
 * @property DisputeStatus $dispute_status
 * @property CreatedVia $created_via
 * @property ActorType $created_by_actor_type
 * @property string|null $created_by_actor_id
 * @property string|null $idempotency_key
 * @property string|null $idempotency_request_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentLink extends Model
{
    use BelongsToMode;
    use BelongsToTenant;

    /** @use HasFactory<PaymentLinkFactory> */
    use HasFactory;

    use HasPrefixedId;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    /** Every write goes through an action with forceFill(). */
    protected $guarded = ['*'];

    protected $attributes = [
        'fx_mode' => 'none',
        'refund_status' => 'none',
        'dispute_status' => 'none',
        'open_count' => 0,
        'pre_payment_validation' => false,
    ];

    public static function resourceType(): ResourceType
    {
        return ResourceType::PaymentLink;
    }

    /**
     * Payment attempts of the link (plan 7.5), newest first. Read-only here:
     * attempts change only through the payments module.
     *
     * @return HasMany<PaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_link_id')->orderByDesc('id');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /** The long card-testing block (plan 11.7 rule 4) is in force. */
    public function isCheckoutBlocked(): bool
    {
        return $this->checkout_blocked_until !== null && $this->checkout_blocked_until->isFuture();
    }

    /** Still `active` but past its expiry: the job (or checkout) expires it. */
    public function isPastExpiry(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(CarbonImmutable::now());
    }

    protected static function newFactory(): PaymentLinkFactory
    {
        return PaymentLinkFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentLinkStatus::class,
            'amount_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'metadata' => AsJsonObject::class,
            'fx_mode' => FxMode::class,
            'fx_fixed_rate' => 'decimal:6',
            'payer_fields_config' => 'array',
            'pre_payment_validation' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'first_opened_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime',
            'open_count' => 'integer',
            'checkout_blocked_until' => 'immutable_datetime',
            'checkout_unblocked_at' => 'immutable_datetime',
            'opened_event_at' => 'immutable_datetime',
            'refund_status' => RefundStatus::class,
            'dispute_status' => DisputeStatus::class,
            'created_via' => CreatedVia::class,
            'created_by_actor_type' => ActorType::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
