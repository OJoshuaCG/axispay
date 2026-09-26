<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Filament\Resources\ApiKeys;

use App\Modules\ApiKeys\Actions\RevokeApiKey;
use App\Modules\ApiKeys\Enums\ApiKeyStatus;
use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use App\Support\Filament\DomainErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant panel: API keys of the current mode (plan 10.2, 17.3;
 * `api_keys:manage`). List, create (ListApiKeys header action: the key is
 * shown once) and revoke. Presentation only; every change goes through an
 * ApiKeys action, which re-checks the permission and the re-authentication
 * window. Keys of the other mode never show (BelongsToMode).
 */
final class ApiKeyResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = ApiKey::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'settings/api-keys';

    public static function getModelLabel(): string
    {
        return __('api_keys.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('api_keys.plural');
    }

    public static function getNavigationGroup(): string
    {
        return __('api_keys.navigation.group');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ViewColumn::make('summary')
                    ->label(__('api_keys.fields.summary'))
                    ->view('filament.api-keys.summary-column')
                    ->hiddenFrom('md'),
                TextColumn::make('name')->label(__('api_keys.fields.name'))->searchable()->sortable()->wrap()->visibleFrom('md'),
                TextColumn::make('key')
                    ->label(__('api_keys.fields.key'))
                    ->state(static fn (ApiKey $record): string => $record->maskedKey())
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['class' => 'break-all'])
                    ->visibleFrom('md'),
                TextColumn::make('scopes')
                    ->label(__('api_keys.fields.scopes'))
                    ->badge()
                    ->color('gray')
                    // Every badge is plain text (no collapsed list behind a
                    // mouse-only toggle); all scopes read as one badge.
                    ->state(static fn (ApiKey $record): array => self::scopeLabels($record))
                    ->wrap()
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->label(__('api_keys.fields.status'))
                    ->badge()
                    ->state(static fn (ApiKey $record): string => $record->status()->label())
                    ->color(static fn (ApiKey $record): string => $record->status()->color())
                    ->icon(static fn (ApiKey $record): Heroicon => $record->status()->icon())
                    ->visibleFrom('md'),
                TextColumn::make('last_used_at')
                    ->label(__('api_keys.fields.last_used_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('api_keys.never_used'))
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('api_keys.fields.created_at'))->dateTime()->sortable()->toggleable()->visibleFrom('xl'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('api_keys.filters.status'))
                    ->options([
                        ApiKeyStatus::Active->value => __('api_keys.filters.active_only'),
                        ApiKeyStatus::Revoked->value => __('api_keys.filters.revoked_only'),
                    ])
                    ->placeholder(__('api_keys.filters.all'))
                    ->default(ApiKeyStatus::Active->value)
                    ->query(static fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        ApiKeyStatus::Active->value => $query->whereNull('revoked_at'),
                        ApiKeyStatus::Revoked->value => $query->whereNotNull('revoked_at'),
                        default => $query,
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCommandLine)
            ->emptyStateHeading(__('api_keys.empty.heading'))
            ->emptyStateDescription(__('api_keys.empty.description'))
            ->emptyStateActions([
                // Opens the header's create form.
                Action::make('createFromEmptyState')
                    ->label(__('api_keys.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize('create', ApiKey::class)
                    ->outlined()
                    ->alpineClickHandler("\$wire.mountAction('create')"),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->recordUrl(null)
            ->recordActions([self::revokeAction()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeys::route('/'),
        ];
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('api_keys.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('revoke')
            ->hidden(static fn (ApiKey $record): bool => $record->isRevoked())
            ->requiresConfirmation()
            ->modalHeading(__('api_keys.actions.revoke_heading'))
            ->modalDescription(static fn (ApiKey $record): string => self::revokeDescription($record))
            ->modalSubmitActionLabel(__('api_keys.actions.revoke_submit'))
            ->schema([Reauthentication::field()])
            ->action(static function (array $data, ApiKey $record, Action $action): void {
                try {
                    Reauthentication::confirm($data);
                    app(RevokeApiKey::class)->handle(TenantPanel::user(), $record);
                } catch (ReauthenticationRequiredException) {
                    DomainErrors::stop(__('api_keys.errors.reauthentication_required'));
                }

                Notification::make()->success()->title(__('api_keys.notifications.revoked'))->send();
            });
    }

    /** Name, masked key, last use and, for live keys, the owner e-mail (plan 17.3, 22). */
    public static function revokeDescription(ApiKey $record): string
    {
        $replace = ['name' => $record->name, 'key' => $record->maskedKey()];
        $text = $record->last_used_at !== null
            ? __('api_keys.actions.revoke_help', [...$replace, 'since' => $record->last_used_at->diffForHumans()])
            : __('api_keys.actions.revoke_help_unused', $replace);

        return $record->livemode ? $text.' '.__('api_keys.actions.revoke_live_note') : $text;
    }

    /**
     * The scope labels of a key, or one "All permissions" badge.
     *
     * @return list<string>
     */
    public static function scopeLabels(ApiKey $record): array
    {
        if (array_diff(ApiScope::values(), $record->scopes) === []) {
            return [__('api_keys.scope_all')];
        }

        return array_values(array_map(static fn (string $scope): string => ApiScope::tryFrom($scope)?->label() ?? $scope, $record->scopes));
    }
}
