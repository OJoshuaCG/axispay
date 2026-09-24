<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\PlatformAdmins;

use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\PlatformAdmin\Filament\Resources\PlatformAdmins\Pages\ListPlatformAdmins;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin panel: list of platform admins (superadmins only). Admins are created
 * from the console (`axispay:create-platform-admin`).
 */
final class PlatformAdminResource extends Resource
{
    protected static ?string $model = PlatformAdmin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 80;

    public static function getModelLabel(): string
    {
        return __('platform.admins.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.admins.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('platform.admins.fields.name'))->searchable()->wrap(),
                TextColumn::make('email')->label(__('platform.admins.fields.email'))->searchable()->wrap(),
                TextColumn::make('role')
                    ->label(__('platform.admins.fields.role'))
                    ->badge()
                    ->formatStateUsing(static fn (PlatformRole $state): string => $state->label()),
                IconColumn::make('two_factor_confirmed_at')
                    ->label(__('platform.admins.fields.two_factor'))
                    ->boolean()
                    ->state(static fn (PlatformAdmin $record): bool => filled($record->two_factor_secret)),
                TextColumn::make('last_login_at')->label(__('platform.admins.fields.last_login_at'))->since()->placeholder('—'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformAdmins::route('/'),
        ];
    }
}
