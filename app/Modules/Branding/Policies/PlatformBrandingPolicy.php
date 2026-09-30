<?php

declare(strict_types=1);

namespace App\Modules\Branding\Policies;

use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * The platform brand (ADR-0053): only platform admins holding
 * `platform:branding:manage` (superadmin, ADR-0014). A tenant user never
 * passes: any user that is not a PlatformAdmin is denied. The parameter is
 * untyped on purpose: Laravel's Gate passes whatever user asks, and a typed
 * PlatformAdmin would throw a TypeError for a tenant User instead of denying.
 */
final class PlatformBrandingPolicy
{
    public function manage(mixed $user): bool
    {
        return $user instanceof PlatformAdmin
            && $user->hasPlatformPermission(PlatformPermission::BrandingManage);
    }
}
