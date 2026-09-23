<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants;

use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ListTenants;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Locales;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Admin panel: tenants (plan 17.4, 21.3). Creation and status changes run
 * through CreateTenant / ChangeTenantStatus; this class only describes the UI.
 */
final class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('platform.tenants.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.tenants.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label(__('platform.tenants.fields.legal_name'))->required()->maxLength(200),
            TextInput::make('display_name')->label(__('platform.tenants.fields.display_name'))->required()->maxLength(120),
            Select::make('timezone')
                ->label(__('platform.tenants.fields.timezone'))
                ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                ->default('America/Mexico_City')
                ->searchable()
                ->required(),
            Select::make('default_locale')
                ->label(__('platform.tenants.fields.default_locale'))
                ->options(Locales::supported())
                ->default('es')
                ->required(),
            TextInput::make('support_email')->label(__('platform.tenants.fields.support_email'))->email()->maxLength(254),
            TextInput::make('owner_email')
                ->label(__('platform.tenants.fields.owner_email'))
                ->helperText(__('platform.tenants.fields.owner_email_help'))
                ->email()
                ->maxLength(254),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label(__('platform.tenants.fields.display_name'))->searchable()->sortable()->wrap(),
                TextColumn::make('legal_name')->label(__('platform.tenants.fields.legal_name'))->searchable()->wrap()->toggleable(),
                TextColumn::make('status')
                    ->label(__('platform.tenants.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (TenantStatus $state): string => $state->label())
                    ->color(static fn (TenantStatus $state): string => self::statusColor($state)),
                TextColumn::make('created_at')->label(__('platform.tenants.fields.created_at'))->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('platform.tenants.fields.status'))->options(TenantStatus::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('display_name')->label(__('platform.tenants.fields.display_name')),
            TextEntry::make('legal_name')->label(__('platform.tenants.fields.legal_name')),
            TextEntry::make('status')
                ->label(__('platform.tenants.fields.status'))
                ->badge()
                ->formatStateUsing(static fn (TenantStatus $state): string => $state->label())
                ->color(static fn (TenantStatus $state): string => self::statusColor($state)),
            TextEntry::make('status_reason')->label(__('platform.tenants.fields.status_reason'))->placeholder('—'),
            TextEntry::make('status_changed_at')->label(__('platform.tenants.fields.status_changed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('timezone')->label(__('platform.tenants.fields.timezone')),
            TextEntry::make('default_locale')->label(__('platform.tenants.fields.default_locale')),
            TextEntry::make('support_email')->label(__('platform.tenants.fields.support_email'))->placeholder('—'),
            TextEntry::make('id')->label(__('platform.tenants.fields.id'))->copyable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'view' => ViewTenant::route('/{record}'),
        ];
    }

    public static function statusColor(TenantStatus $status): string
    {
        return match ($status) {
            TenantStatus::Active => 'success',
            TenantStatus::Grace, TenantStatus::PendingOnboarding => 'info',
            TenantStatus::Suspended => 'warning',
            TenantStatus::Closed => 'danger',
        };
    }
}
