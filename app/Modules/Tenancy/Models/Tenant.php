<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer of the platform (plan 7.1). Not tenant-scoped itself: it is the
 * root the `tenant_id` columns point at. Status changes go through
 * ChangeTenantStatus only.
 *
 * @property string $id
 * @property string $legal_name
 * @property string $display_name
 * @property TenantStatus $status
 * @property string|null $status_reason
 * @property CarbonImmutable|null $status_changed_at
 * @property string $timezone
 * @property string $default_locale
 * @property string|null $support_email
 * @property string|null $privacy_notice_url
 * @property list<string>|null $allowed_return_domains
 * @property array<mixed>|null $settings
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $fillable = [
        'legal_name',
        'display_name',
        'timezone',
        'default_locale',
        'support_email',
        'privacy_notice_url',
        'allowed_return_domains',
        'settings',
    ];

    protected $attributes = [
        'status' => 'pending_onboarding',
        'timezone' => 'America/Mexico_City',
        'default_locale' => 'es',
    ];

    public function settings(): TenantSettings
    {
        return TenantSettings::fromArray($this->settings ?? []);
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'status_changed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'allowed_return_domains' => 'array',
            'settings' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
