<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Models;

use App\Modules\ApiKeys\Enums\ApiKeyStatus;
use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An API key of a tenant in one mode (plan 7.2, ADR-015). The key itself is
 * never stored: only its SHA-256 (`key_hash`, hidden from serialization) and
 * the visible `key_prefix` / `key_last4`. Writes go through the ApiKeys
 * actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $name
 * @property string $key_prefix
 * @property string $key_last4
 * @property string $key_hash
 * @property list<string> $scopes
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_used_ip
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by_user_id
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ApiKey extends Model
{
    use BelongsToMode;
    use BelongsToTenant;

    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    /** Every write goes through an action with forceFill(). */
    protected $guarded = ['*'];

    protected $hidden = ['key_hash'];

    /**
     * Keys that can still authenticate (not revoked, not expired).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(static fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function status(): ApiKeyStatus
    {
        return match (true) {
            $this->isRevoked() => ApiKeyStatus::Revoked,
            $this->isExpired() => ApiKeyStatus::Expired,
            default => ApiKeyStatus::Active,
        };
    }

    public function hasScope(ApiScope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    /** `axp_live_a1b2…9xYz`: the only form of the key shown after creation. */
    public function maskedKey(): string
    {
        return $this->key_prefix.'…'.$this->key_last4;
    }

    protected static function newFactory(): ApiKeyFactory
    {
        return ApiKeyFactory::new();
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
