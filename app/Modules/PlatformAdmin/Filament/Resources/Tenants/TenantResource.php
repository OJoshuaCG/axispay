<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants;

use App\Modules\Identity\Services\UserDirectory;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\EditTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ListTenants;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers\InvitationsRelationManager;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use App\Modules\PlatformAdmin\Services\TenantOwnership;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use App\Support\Locales;
use BackedEnum;
use Closure;
use DateTimeZone;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin panel: tenants (plan 17.4, 21.3, ADR-0043). Creation, profile edits,
 * owner invitations and status changes run through CreateTenant,
 * UpdateTenantProfile, InviteTenantOwner and ChangeTenantStatus; this class
 * only describes the UI. There is no delete: a tenant is retired by closing
 * it (audit_logs reference it with ON DELETE RESTRICT, append-only).
 *
 * The owner e-mail is required on creation and the list shows whether each
 * tenant has an active owner (plan 17.2, ADR-0045, TenantOwnership).
 */
final class TenantResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Tenant::class;

    /** Page titles and breadcrumbs name the record (ADR-0044). */
    protected static ?string $recordTitleAttribute = 'display_name';

    /** A title attribute would switch on global search, which is not wanted. */
    protected static bool $isGloballySearchable = false;

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
                ->required()
                ->maxLength(254)
                // Checked again by the CreateTenant action; here it only
                // puts the message next to the field before submitting.
                ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && app(UserDirectory::class)->emailIsRegistered($value)) {
                        $fail(__('platform.tenants.errors.owner_email_taken'));
                    }
                })
                ->visibleOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => app(TenantOwnership::class)->withOwnershipColumns($query))
            ->columns([
                TextColumn::make('display_name')->label(__('platform.tenants.fields.display_name'))->searchable()->sortable()->wrap(),
                // Phones keep name, status and owner in view (ADR-0045).
                TextColumn::make('legal_name')->label(__('platform.tenants.fields.legal_name'))->searchable()->wrap()->toggleable()->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('platform.tenants.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (TenantStatus $state): string => $state->label())
                    ->color(static fn (TenantStatus $state): string => self::statusColor($state)),
                TextColumn::make('ownership')
                    ->label(__('platform.tenants.fields.owner'))
                    ->badge()
                    ->state(static fn (Tenant $record): string => app(TenantOwnership::class)->stateOf($record)->label())
                    ->color(static fn (Tenant $record): string => app(TenantOwnership::class)->stateOf($record)->color()),
                TextColumn::make('created_at')->label(__('platform.tenants.fields.created_at'))->date()->sortable()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('platform.tenants.fields.status'))->options(TenantStatus::options()),
                Filter::make('without_active_owner')
                    ->label(__('platform.tenants.filters.without_active_owner'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => app(TenantOwnership::class)->whereHasActiveOwner($query, false)),
            ])
            ->defaultSort('created_at', 'desc')
            // A row opens the view page (ListRecords' default record URL), so
            // there is no separate "View" action (ADR-0044).
            ->recordActions([EditAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2)
            ->emptyStateHeading(__('platform.tenants.empty.heading'))
            ->emptyStateDescription(__('platform.tenants.empty.description'))
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([self::profileSection()]);
    }

    /** Also used by ViewTenant, which puts the ownership warning above it. */
    public static function profileSection(): Section
    {
        return Section::make(__('platform.tenants.sections.profile'))
            ->columns(2)
            ->columnSpanFull()
            ->schema(self::profileEntries());
    }

    /**
     * @return list<TextEntry>
     */
    private static function profileEntries(): array
    {
        return [
            TextEntry::make('display_name')->label(__('platform.tenants.fields.display_name')),
            TextEntry::make('legal_name')->label(__('platform.tenants.fields.legal_name')),
            TextEntry::make('status')
                ->label(__('platform.tenants.fields.status'))
                ->badge()
                ->formatStateUsing(static fn (TenantStatus $state): string => $state->label())
                ->color(static fn (TenantStatus $state): string => self::statusColor($state))
                ->helperText(__('platform.tenants.fields.status_help')),
            TextEntry::make('status_reason')->label(__('platform.tenants.fields.status_reason'))->placeholder('—'),
            TextEntry::make('status_changed_at')->label(__('platform.tenants.fields.status_changed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('timezone')->label(__('platform.tenants.fields.timezone')),
            TextEntry::make('default_locale')->label(__('platform.tenants.fields.default_locale')),
            TextEntry::make('support_email')
                ->label(__('platform.tenants.fields.support_email'))
                ->formatStateUsing(static fn (?string $state): ?string => PlatformPii::email($state))
                ->placeholder('—'),
            TextEntry::make('id')->label(__('platform.tenants.fields.id'))->fontFamily(FontFamily::Mono)->copyable(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'view' => ViewTenant::route('/{record}'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            InvitationsRelationManager::class,
            UsersRelationManager::class,
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
