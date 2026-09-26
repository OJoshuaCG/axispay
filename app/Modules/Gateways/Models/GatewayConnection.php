<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Models;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\DisconnectReason;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's connection to a gateway account in one mode (plan 7.4). State
 * changes go through the Gateways actions, under a row lock.
 *
 * The credential columns hold ciphertext of GatewayCredentialsEncrypter and
 * are hidden from serialization: they never reach arrays, JSON, Livewire or
 * logs. Only StripeClientFactory decrypts them, in memory, per call.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property GatewayProvider $provider
 * @property ConnectionMethod $connection_method
 * @property string|null $provider_account_id
 * @property string|null $country
 * @property string|null $default_currency
 * @property ConnectionStatus $status
 * @property bool $charges_enabled
 * @property bool $payouts_enabled
 * @property bool $details_submitted
 * @property array<string, mixed>|null $requirements
 * @property string|null $credentials_secret
 * @property string|null $credentials_publishable
 * @property string|null $credentials_fingerprint
 * @property string|null $credentials_last4
 * @property int|null $credentials_key_version
 * @property string|null $provider_webhook_endpoint_id
 * @property string|null $provider_webhook_secret
 * @property array<string, mixed>|null $validated_permissions
 * @property CarbonImmutable|null $last_health_check_at
 * @property HealthCheckStatus|null $last_health_check_status
 * @property string|null $oauth_scope
 * @property CarbonImmutable|null $risk_acknowledged_at
 * @property string|null $risk_acknowledged_by_user_id
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property DisconnectReason|null $disconnect_reason
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class GatewayConnection extends Model
{
    use BelongsToMode;
    use BelongsToTenant;

    /** @use HasFactory<GatewayConnectionFactory> */
    use HasFactory;

    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    /** Every write goes through an action with forceFill(). */
    protected $guarded = ['*'];

    protected $hidden = [
        'credentials_secret',
        'credentials_fingerprint',
        'provider_webhook_secret',
        'active_slot',
        'active_provider_account_id',
    ];

    protected $attributes = [
        'provider' => 'stripe',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
    ];

    /**
     * The connection that is not disconnected, if any (at most one per
     * tenant, provider and mode; enforced by a unique index).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query, GatewayProvider $provider = GatewayProvider::Stripe): Builder
    {
        return $query->where('provider', $provider->value)->where('status', '!=', ConnectionStatus::Disconnected->value);
    }

    public function isApiKey(): bool
    {
        return $this->connection_method === ConnectionMethod::ApiKey;
    }

    /** `rk_live_…a1b2`: the only form of the secret the panel ever shows. */
    public function maskedSecret(): ?string
    {
        if ($this->credentials_last4 === null) {
            return null;
        }

        return 'rk_'.($this->livemode ? 'live' : 'test').'_…'.$this->credentials_last4;
    }

    protected static function newFactory(): GatewayConnectionFactory
    {
        return GatewayConnectionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'provider' => GatewayProvider::class,
            'connection_method' => ConnectionMethod::class,
            'status' => ConnectionStatus::class,
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'requirements' => 'array',
            'credentials_key_version' => 'integer',
            'validated_permissions' => 'array',
            'last_health_check_at' => 'immutable_datetime',
            'last_health_check_status' => HealthCheckStatus::class,
            'risk_acknowledged_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
            'disconnect_reason' => DisconnectReason::class,
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
