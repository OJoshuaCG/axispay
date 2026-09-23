<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/**
 * A tenant user (plan 7.2). One tenant per user in the MVP; e-mail is unique
 * across the platform. 2FA secrets and recovery codes are encrypted at rest;
 * recovery codes are additionally hashed (Filament's AppAuthentication).
 *
 * Authorization always checks permissions (TenantPermission), never role
 * names (ADR-014).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlidPrimaryKey;
    use Notifiable;
    use UsesMicrosecondDates;

    /** spatie/laravel-permission guard for tenant users. */
    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'app'
            && ! $this->isDisabled()
            && $this->tenant !== null
            && $this->tenant->status->allowsPanelAccess();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret);
    }

    /**
     * Plan 17.3: 2FA is mandatory for users holding any sensitive permission
     * (owner, admin, integration_manager and finance by default).
     */
    public function requiresTwoFactor(): bool
    {
        foreach (TenantPermission::sensitive() as $permission) {
            if ($this->checkPermissionTo($permission->value)) {
                return true;
            }
        }

        return false;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $secret === null ? null : now(),
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>|null
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes === null ? null : array_values($codes)])->save();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
