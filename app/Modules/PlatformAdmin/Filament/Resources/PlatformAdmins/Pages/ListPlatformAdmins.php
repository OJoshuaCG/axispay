<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\PlatformAdmins\Pages;

use App\Modules\PlatformAdmin\Filament\Resources\PlatformAdmins\PlatformAdminResource;
use Filament\Resources\Pages\ListRecords;

final class ListPlatformAdmins extends ListRecords
{
    protected static string $resource = PlatformAdminResource::class;
}
