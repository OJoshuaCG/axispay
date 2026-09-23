<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\AuditLogs;

use App\Modules\Audit\Filament\Support\AuditLogPresenter;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\PlatformAdmin\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Modules\PlatformAdmin\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Modules\Tenancy\Scopes\TenantScope;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin panel: the whole audit log, platform and tenant entries (read-only).
 * Reads without the tenant scope; allowed because the PlatformAdmin module is
 * on the scope-bypass whitelist (plan 6.5).
 */
final class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'audit-logs';

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

    /**
     * @return Builder<AuditLog>
     */
    public static function getEloquentQuery(): Builder
    {
        return AuditLog::query()->withoutGlobalScope(TenantScope::class)->with('tenant');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...AuditLogPresenter::columns(),
                TextColumn::make('tenant.display_name')->label(__('audit.fields.tenant'))->placeholder(__('audit.platform'))->wrap(),
            ])
            ->filters([
                AuditLogPresenter::actionFilter(),
                TernaryFilter::make('platform')
                    ->label(__('audit.filters.scope'))
                    ->trueLabel(__('audit.filters.platform_only'))
                    ->falseLabel(__('audit.filters.tenants_only'))
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereNull('tenant_id'),
                        false: static fn (Builder $query): Builder => $query->whereNotNull('tenant_id'),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('tenant.display_name')->label(__('audit.fields.tenant'))->placeholder(__('audit.platform')),
            ...AuditLogPresenter::entries(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
