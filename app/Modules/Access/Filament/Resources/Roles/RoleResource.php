<?php

declare(strict_types=1);

namespace App\Modules\Access\Filament\Resources\Roles;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\Access\Filament\Resources\Roles\Pages\ViewRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant panel: read-only view of the roles a tenant can assign (the global
 * system roles plus its own, plan 17.2). Roles are edited only by the
 * superadmin (RolePolicy).
 */
final class RoleResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('access.roles.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('access.roles.plural');
    }

    public static function getNavigationGroup(): string
    {
        return __('identity.navigation.group');
    }

    /**
     * @return Builder<Role>
     */
    public static function getEloquentQuery(): Builder
    {
        return Role::query()->visibleToCurrentTenant()->where('guard_name', TenantPermission::GUARD)->with('permissions');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('access.roles.fields.name'))
                    ->state(static fn (Role $record): string => $record->displayName())
                    ->wrap(),
                TextColumn::make('permissions_count')
                    ->label(__('access.roles.fields.permissions_count'))
                    ->counts('permissions'),
                TextColumn::make('type')
                    ->label(__('access.roles.fields.type'))
                    ->badge()
                    ->state(static fn (Role $record): string => $record->isSystem() ? __('access.roles.type.system') : __('access.roles.type.custom')),
            ])
            // A row opens the role (ListRecords' default record URL).
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(__('access.roles.empty.heading'))
            ->emptyStateDescription(__('access.roles.empty.description'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('access.roles.fields.name'))
                ->state(static fn (Role $record): string => $record->displayName()),
            TextEntry::make('permissions')
                ->label(__('access.roles.fields.permissions'))
                ->badge()
                ->state(static fn (Role $record): array => self::permissionLabels($record)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'view' => ViewRole::route('/{record}'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function permissionLabels(Role $role): array
    {
        return array_values($role->permissions
            ->map(static function (mixed $permission): string {
                if (! $permission instanceof Permission) {
                    return '';
                }

                return TenantPermission::tryFrom($permission->name)?->label() ?? $permission->name;
            })
            ->filter()
            ->all());
    }
}
