<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Policies;

use App\Modules\PlatformAdmin\Models\PlatformAdmin;

/**
 * Platform admins are listed for superadmins only and created from the
 * console (`paylink:create-platform-admin`).
 */
final class PlatformAdminPolicy
{
    public function viewAny(PlatformAdmin $admin): bool
    {
        return $admin->isSuperadmin();
    }

    public function view(PlatformAdmin $admin, PlatformAdmin $other): bool
    {
        return $admin->isSuperadmin();
    }

    public function create(PlatformAdmin $admin): bool
    {
        return false;
    }

    public function update(PlatformAdmin $admin, PlatformAdmin $other): bool
    {
        return false;
    }

    public function delete(PlatformAdmin $admin, PlatformAdmin $other): bool
    {
        return false;
    }

    public function deleteAny(PlatformAdmin $admin): bool
    {
        return false;
    }
}
