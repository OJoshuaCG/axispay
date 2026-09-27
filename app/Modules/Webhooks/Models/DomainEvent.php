<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\DomainEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded business event (ADR-0051), written in the same transaction as
 * the change it describes. Phase 5 publishes it to the outbox of outgoing
 * webhooks (plan 15.4) and sets `published_at`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property DomainEventType $type
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed> $data
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $published_at
 */
final class DomainEvent extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => DomainEventType::class,
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
