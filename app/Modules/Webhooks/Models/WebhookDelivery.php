<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\WebhookDeliveryError;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt of an event to an endpoint (plan 7.6). Each retry is
 * a new row with the next `attempt_number`; the response is kept as a
 * sanitized excerpt of at most 2 KB. Writes go through DeliverWebhook and
 * the Webhooks actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $webhook_event_id
 * @property string $webhook_endpoint_id
 * @property WebhookDeliveryTrigger $trigger
 * @property int $attempt_number
 * @property WebhookDeliveryStatus $status
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $sent_at
 * @property int|null $response_status
 * @property string|null $response_body_excerpt
 * @property int|null $duration_ms
 * @property WebhookDeliveryError|null $error
 * @property CarbonImmutable|null $next_retry_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WebhookDelivery extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function succeeded(): bool
    {
        return $this->status === WebhookDeliveryStatus::Succeeded;
    }

    /** Refused by the SSRF protection (plan 15.7 rule 7). */
    public function isBlockedDestination(): bool
    {
        return $this->error === WebhookDeliveryError::BlockedDestination;
    }

    protected function casts(): array
    {
        return [
            'trigger' => WebhookDeliveryTrigger::class,
            'attempt_number' => 'integer',
            'status' => WebhookDeliveryStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'error' => WebhookDeliveryError::class,
            'next_retry_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
