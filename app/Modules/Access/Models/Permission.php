<?php

declare(strict_types=1);

namespace App\Modules\Access\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permission with a ULID key (ADR-020). The catalog is global
 * (App\Modules\Access\Enums\TenantPermission).
 *
 * @property string $id
 */
final class Permission extends SpatiePermission
{
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;
}
