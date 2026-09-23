<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Resources\Users\Pages;

use App\Modules\Identity\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::changeRolesAction(),
            UserResource::deactivateAction(),
            UserResource::reactivateAction(),
        ];
    }
}
