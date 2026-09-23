<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Resources\AuditLogs\Pages;

use App\Modules\Audit\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;
}
