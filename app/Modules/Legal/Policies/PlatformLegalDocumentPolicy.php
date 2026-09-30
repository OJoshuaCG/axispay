<?php

declare(strict_types=1);

namespace App\Modules\Legal\Policies;

use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * The platform's legal documents (ADR-0056): only platform admins holding
 * `platform:legal:manage` (superadmin). A tenant user never passes: the
 * ability takes a PlatformAdmin, so any other user is denied.
 */
final class PlatformLegalDocumentPolicy
{
    public function manage(PlatformAdmin $admin): bool
    {
        return $admin->hasPlatformPermission(PlatformPermission::LegalManage);
    }
}
