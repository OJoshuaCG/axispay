<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * The two kinds of sign-in accounts: platform admins (`platform` guard, admin
 * host) and tenant users (`web` guard, app host). Used by the account
 * recovery commands to disambiguate an e-mail present in both tables.
 */
enum AccountType: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';

    public static function of(User|PlatformAdmin $account): self
    {
        return $account instanceof PlatformAdmin ? self::Platform : self::Tenant;
    }

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'platform admin',
            self::Tenant => 'tenant user',
        };
    }
}
