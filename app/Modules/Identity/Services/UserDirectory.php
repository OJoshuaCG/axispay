<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Scopes\TenantScope;

/**
 * Platform-wide e-mail uniqueness check (plan 7.2: e-mail is unique across
 * tenants in the MVP). Returns only a boolean, never another tenant's user.
 */
final class UserDirectory
{
    public function emailIsRegistered(string $email): bool
    {
        return User::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('email', self::normalize($email))
            ->exists();
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
