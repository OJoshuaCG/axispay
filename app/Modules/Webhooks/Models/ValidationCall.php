<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\ValidationFinalDecision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pre-payment validation call (plan 7.6, 15.8.7), or one test call from
 * the panel (`is_test`). Its ID is the `webhook-id` of the call and the
 * public `val_...` of the body. The request body holds the payer's e-mail
 * and name: encrypted at rest and hidden from serialization (rules.md rule
 * 10). Kept 30 days (ValidationCallRetention).
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string|null $validation_endpoint_id
 * @property string|null $payment_link_id
 * @property string|null $payment_attempt_id
 * @property int|null $attempt_number
 * @property bool $is_test
 * @property array<string, mixed> $request_payload
 * @property ValidationCallOutcome|null $outcome
 * @property ValidationFailureKind|null $failure_kind
 * @property ValidationFailurePolicy|null $policy_applied
 * @property ValidationFinalDecision|null $final_decision
 * @property string|null $reason_code
 * @property string|null $payer_message
 * @property bool $cancel_link
 * @property bool $connection_retried
 * @property int|null $response_status
 * @property string|null $response_body_excerpt
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ValidationCall extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasPrefixedId;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['request_payload'];

    protected $attributes = [
        'is_test' => false,
        'cancel_link' => false,
        'connection_retried' => false,
    ];

    public static function resourceType(): ResourceType
    {
        return ResourceType::ValidationCall;
    }

    /**
     * The link the call validated (null for a test call).
     *
     * @return BelongsTo<PaymentLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'is_test' => 'boolean',
            'request_payload' => 'encrypted:array',
            'outcome' => ValidationCallOutcome::class,
            'failure_kind' => ValidationFailureKind::class,
            'policy_applied' => ValidationFailurePolicy::class,
            'final_decision' => ValidationFinalDecision::class,
            'cancel_link' => 'boolean',
            'connection_retried' => 'boolean',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
