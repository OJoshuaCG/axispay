<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Resources\AuditLogs;

use App\Modules\Audit\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Modules\Audit\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Modules\Audit\Filament\Support\AuditLogPresenter;
use App\Modules\Audit\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Tenant panel: the tenant's own audit log (plan 17.1 `audit:read`),
 * read-only. Tenant-scoped by BelongsToTenant; platform rows (NULL tenant)
 * never appear.
 */
final class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('audit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(AuditLogPresenter::columns())
            ->filters([AuditLogPresenter::actionFilter()])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(AuditLogPresenter::entries());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
