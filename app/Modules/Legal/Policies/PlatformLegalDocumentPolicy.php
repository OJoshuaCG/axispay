<?php

declare(strict_types=1);

namespace App\Modules\Legal\Policies;

use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * The platform's legal documents (ADR-0056): only platform admins holding
 * `platform:legal:manage` (superadmin). A tenant user never passes: any
 * user that is not a PlatformAdmin is denied. The parameter is untyped on
 * purpose: Laravel's Gate passes whatever user asks, and a typed PlatformAdmin
 * would throw a TypeError for a tenant User instead of denying.
 */
final class PlatformLegalDocumentPolicy
{
    public function manage(mixed $user): bool
    {
        return $user instanceof PlatformAdmin
            && $user->hasPlatformPermission(PlatformPermission::LegalManage);
    }
}
