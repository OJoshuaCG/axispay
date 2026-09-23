<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Support;

use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Filament\Facades\Filament;

final class PlatformActor
{
    public static function current(): PlatformAdmin
    {
        $admin = Filament::auth()->user();

        abort_unless($admin instanceof PlatformAdmin, 403);

        return $admin;
    }
}
