<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One idempotency key of a tenant in one mode (plan 7.8, 10.3). Written only
 * by IdempotencyStore.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $api_key_id
 * @property string $idempotency_key
 * @property string $request_method
 * @property string $request_path
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body the exact JSON body sent, replayed byte for byte
 * @property CarbonImmutable|null $locked_until
 * @property string|null $lock_token
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IdempotencyRecord extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['lock_token'];

    public function hasResponse(): bool
    {
        return $this->response_status !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'locked_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
