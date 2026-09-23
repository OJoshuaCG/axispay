<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Exceptions\AuditLogImmutableException;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Contracts\AllowsPlatformRows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit entry (plan 7.1). `tenant_id` is NULL for platform events;
 * those rows are never visible through the tenant scope. Entries are written
 * only by AuditLogger.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property ActorType $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<mixed>|null $changes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
final class AuditLog extends Model implements AllowsPlatformRows
{
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new AuditLogImmutableException);
        self::deleting(static fn (): never => throw new AuditLogImmutableException);
    }

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
