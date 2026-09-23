<?php

declare(strict_types=1);

namespace App\Modules\Access\Filament\Resources\Roles\Pages;

use App\Modules\Access\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;
}
