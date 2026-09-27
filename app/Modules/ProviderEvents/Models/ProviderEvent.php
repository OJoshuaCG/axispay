<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Models;

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Contracts\AllowsPlatformRows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An incoming gateway webhook (plan 7.6). Routed events belong to the tenant
 * of their connection; unroutable ones are platform rows (`tenant_id` NULL,
 * ADR-0031) and never visible through the tenant scope. `payload` is the raw
 * body exactly as received.
 *
 * @property string $id
 * @property GatewayProvider $provider
 * @property string $provider_event_id
 * @property string|null $provider_account_id
 * @property bool $livemode
 * @property string $type
 * @property string|null $object_id
 * @property string|null $payment_attempt_id
 * @property string $payload encrypted at rest (ADR-0051)
 * @property bool $payload_reduced only the reduced envelope is kept
 * @property string|null $tenant_id
 * @property string|null $gateway_connection_id
 * @property ProviderEventStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ProviderEvent extends Model implements AllowsPlatformRows
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    /** The raw payload can hold account details: never serialized to arrays or JSON. */
    protected $hidden = ['payload'];

    protected $attributes = [
        'attempts' => 0,
        'payload_reduced' => false,
    ];

    protected function casts(): array
    {
        return [
            'provider' => GatewayProvider::class,
            'status' => ProviderEventStatus::class,
            // The event body may hold account or payer details (ADR-0051).
            'payload' => 'encrypted',
            'payload_reduced' => 'boolean',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
