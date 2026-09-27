<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One gateway payment of a link (plan 7.5, 9.2): 1:1 with the gateway's
 * payment; a decline does not create a new attempt. `status` changes only
 * through PaymentAttemptStateMachine. Exposed as `pay_<ULID>`; gateway IDs
 * never leave the panel (ADR-019).
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $payment_link_id
 * @property GatewayProvider $provider
 * @property string|null $provider_payment_id
 * @property string|null $provider_account_id
 * @property string $gateway_connection_id
 * @property PaymentAttemptStatus $status
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property int $original_amount_minor
 * @property CurrencyCode $original_currency
 * @property string|null $fx_quote_id
 * @property string|null $card_country
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property int $failure_count
 * @property string|null $last_failure_code
 * @property string|null $last_failure_message
 * @property string|null $last_decline_code
 * @property string|null $client_ip
 * @property string|null $user_agent
 * @property CarbonImmutable|null $confirmation_lease_until
 * @property string|null $confirmation_lease_token
 * @property ValidationOutcome|null $validation_outcome
 * @property string|null $validation_payer_message
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $capture_before
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $canceled_at
 * @property int $amount_refunded_minor
 * @property bool $late_payment
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentAttempt extends Model
{
    use BelongsToMode;
    use BelongsToTenant;

    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory;

    use HasPrefixedId;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    /** Fraud-analysis data stays out of arrays and JSON. */
    protected $hidden = ['client_ip', 'user_agent', 'active_link_id', 'confirmation_lease_until', 'confirmation_lease_token'];

    protected $attributes = [
        'provider' => 'stripe',
        'failure_count' => 0,
        'amount_refunded_minor' => 0,
        'late_payment' => false,
        'needs_review' => false,
    ];

    public static function resourceType(): ResourceType
    {
        return ResourceType::Payment;
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function leaseHeld(): bool
    {
        return $this->confirmation_lease_until !== null && $this->confirmation_lease_until->isFuture();
    }

    /**
     * @return HasMany<PaymentAttemptFailure, $this>
     */
    public function failures(): HasMany
    {
        return $this->hasMany(PaymentAttemptFailure::class, 'payment_attempt_id');
    }

    protected static function newFactory(): PaymentAttemptFactory
    {
        return PaymentAttemptFactory::new();
    }

    protected function casts(): array
    {
        return [
            'provider' => GatewayProvider::class,
            'status' => PaymentAttemptStatus::class,
            'amount_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'original_amount_minor' => 'integer',
            'original_currency' => CurrencyCode::class,
            'failure_count' => 'integer',
            'confirmation_lease_until' => 'immutable_datetime',
            'validation_outcome' => ValidationOutcome::class,
            'authorized_at' => 'immutable_datetime',
            'capture_before' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'amount_refunded_minor' => 'integer',
            'late_payment' => 'boolean',
            'needs_review' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
