<?php

declare(strict_types=1);

namespace App\Modules\Branding\Policies;

use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * The platform brand (ADR-0053): only platform admins holding
 * `platform:branding:manage` (superadmin, ADR-0014). A tenant user never
 * passes: the ability takes a PlatformAdmin, so any other user is denied.
 */
final class PlatformBrandingPolicy
{
    public function manage(PlatformAdmin $admin): bool
    {
        return $admin->hasPlatformPermission(PlatformPermission::BrandingManage);
    }
}
