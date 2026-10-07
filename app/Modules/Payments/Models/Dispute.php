<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\DisputeState;
use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dispute the gateway opened against a payment (plan 7.5, 16.2). With direct
 * charges the merchant answers it in the gateway's own dashboard; the platform
 * records it and tells the integrator. `status` changes only through
 * ApplyProviderDispute. Exposed as `dsp_<ULID>`; the gateway's dispute ID
 * never leaves the panel (ADR-019).
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $payment_attempt_id
 * @property string $provider_dispute_id
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property string|null $reason
 * @property DisputeState $status
 * @property CarbonImmutable|null $evidence_due_by
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Dispute extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasPrefixedId;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    /** Column limit of the gateway's reason (plan 7.5). */
    public const int REASON_MAX = 64;

    protected $guarded = ['*'];

    public static function resourceType(): ResourceType
    {
        return ResourceType::Dispute;
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

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'status' => DisputeState::class,
            'evidence_due_by' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
