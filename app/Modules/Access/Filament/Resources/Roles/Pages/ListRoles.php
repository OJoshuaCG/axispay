<?php

declare(strict_types=1);

namespace App\Modules\Access\Filament\Resources\Roles\Pages;

use App\Modules\Access\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\ListRecords;

final class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;
}
