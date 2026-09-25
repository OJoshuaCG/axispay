<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Support;

use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Filament\Facades\Filament;

/**
 * Plan 17.4: `support_readonly` sees PII masked; superadmins see it in full
 * (they support and bootstrap tenants). Presentation only.
 */
final class PlatformPii
{
    public static function email(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return $email;
        }

        $viewer = Filament::auth()->user();

        if ($viewer instanceof PlatformAdmin && $viewer->isSuperadmin()) {
            return $email;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
