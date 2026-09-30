<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Shared\Database\HasPrefixedId;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outgoing webhook event (plan 7.6 outbox, 15.3). `payload` is the exact
 * body sent, frozen when the event is created: it is kept as the raw JSON
 * string (no cast), so every retry sends the same bytes and the signature
 * always covers them. The public ID `evt_...` is also the `webhook-id`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property WebhookEventType $type
 * @property string|null $domain_event_id
 * @property string $payload
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WebhookEvent extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasPrefixedId;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    public static function resourceType(): ResourceType
    {
        return ResourceType::Event;
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_event_id');
    }

    /** The frozen body, byte for byte. */
    public function body(): string
    {
        return $this->payload;
    }

    protected function casts(): array
    {
        return [
            'type' => WebhookEventType::class,
            'dispatched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
