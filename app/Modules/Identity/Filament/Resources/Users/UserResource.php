<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Resources\Users;

use App\Modules\Access\Actions\ChangeUserRoles;
use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Exceptions\RoleChangeNotAllowedException;
use App\Modules\Access\Models\Role;
use App\Modules\Identity\Actions\DeactivateUser;
use App\Modules\Identity\Actions\ReactivateUser;
use App\Modules\Identity\Exceptions\CannotDeactivateUserException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Identity\Filament\Resources\Users\Pages\ViewUser;
use App\Modules\Identity\Models\User;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant panel: users of the current tenant (plan 17.1 `users:manage`).
 * Presentation only; every change goes through an Action (rules.md rule 12).
 * The query is tenant-scoped by BelongsToTenant, so another tenant's user is
 * a 404 (plan 6.6).
 */
final class UserResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = User::class;

    /** Page titles and breadcrumbs name the record (ADR-0044). */
    protected static ?string $recordTitleAttribute = 'name';

    /** A title attribute would switch on global search, which is not wanted. */
    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('identity.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.users.plural');
    }

    public static function getNavigationGroup(): string
    {
        return __('identity.navigation.group');
    }

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<User> $query */
        $query = parent::getEloquentQuery();

        return $query->with('roles');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('identity.users.fields.name'))->searchable()->sortable()->wrap(),
                TextColumn::make('email')->label(__('identity.users.fields.email'))->searchable()->wrap(),
                TextColumn::make('roles')
                    ->label(__('identity.users.fields.roles'))
                    ->badge()
                    ->state(static fn (User $record): array => self::roleLabels($record)),
                IconColumn::make('two_factor_confirmed_at')
                    ->label(__('identity.users.fields.two_factor'))
                    ->boolean()
                    ->state(static fn (User $record): bool => $record->hasTwoFactorEnabled()),
                TextColumn::make('status')
                    ->label(__('identity.users.fields.status'))
                    ->badge()
                    ->state(static fn (User $record): string => $record->isDisabled() ? __('identity.users.status.disabled') : __('identity.users.status.active'))
                    ->color(static fn (User $record): string => $record->isDisabled() ? 'gray' : 'success'),
                TextColumn::make('last_login_at')->label(__('identity.users.fields.last_login_at'))->since()->sortable()->toggleable(),
            ])
            ->defaultSort('name')
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(__('identity.users.empty.heading'))
            ->emptyStateDescription(__('identity.users.empty.description'))
            // A row opens the user (ListRecords' default record URL), so there
            // is no separate "View" action (ADR-0044).
            ->recordActions([
                self::changeRolesAction(),
                self::deactivateAction(),
                self::reactivateAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label(__('identity.users.fields.name')),
            TextEntry::make('email')->label(__('identity.users.fields.email')),
            TextEntry::make('roles')
                ->label(__('identity.users.fields.roles'))
                ->badge()
                ->state(static fn (User $record): array => self::roleLabels($record)),
            IconEntry::make('two_factor_confirmed_at')
                ->label(__('identity.users.fields.two_factor'))
                ->boolean()
                ->state(static fn (User $record): bool => $record->hasTwoFactorEnabled()),
            TextEntry::make('last_login_at')->label(__('identity.users.fields.last_login_at'))->dateTime()->placeholder('—'),
            TextEntry::make('disabled_at')->label(__('identity.users.fields.disabled_at'))->dateTime()->placeholder('—'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }

    public static function changeRolesAction(): Action
    {
        return Action::make('changeRoles')
            ->label(__('identity.users.actions.change_roles'))
            ->icon(Heroicon::OutlinedKey)
            ->authorize('assignRoles')
            ->fillForm(static fn (User $record): array => ['roles' => $record->roles->pluck('name')->all()])
            ->schema([
                CheckboxList::make('roles')
                    ->label(__('identity.users.fields.roles'))
                    ->options(SystemRole::options())
                    ->required(),
                Reauthentication::field(),
            ])
            ->action(static function (array $data, User $record, Action $action): void {
                Reauthentication::confirm($data);

                $roles = [];

                foreach (is_array($data['roles'] ?? null) ? $data['roles'] : [] as $value) {
                    $role = is_string($value) ? SystemRole::tryFrom($value) : null;

                    if ($role !== null) {
                        $roles[] = $role;
                    }
                }

                try {
                    app(ChangeUserRoles::class)->handle(self::actor(), $record, $roles);
                } catch (RoleChangeNotAllowedException $e) {
                    Notification::make()->danger()->title(__('identity.users.errors.role_change'))->body(__('access.errors.'.$e->reason))->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('identity.users.notifications.roles_changed'))->send();
            });
    }

    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label(__('identity.users.actions.deactivate'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('deactivate')
            ->requiresConfirmation()
            ->modalDescription(__('identity.users.actions.deactivate_confirm'))
            ->schema([Reauthentication::field()])
            ->action(static function (array $data, User $record, Action $action): void {
                Reauthentication::confirm($data);

                try {
                    app(DeactivateUser::class)->handle(self::actor(), $record);
                } catch (CannotDeactivateUserException) {
                    Notification::make()->danger()->title(__('identity.users.errors.deactivate'))->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('identity.users.notifications.deactivated'))->send();
            });
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label(__('identity.users.actions.reactivate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('reactivate')
            ->requiresConfirmation()
            ->schema([Reauthentication::field()])
            ->action(static function (array $data, User $record): void {
                Reauthentication::confirm($data);
                app(ReactivateUser::class)->handle(self::actor(), $record);
                Notification::make()->success()->title(__('identity.users.notifications.reactivated'))->send();
            });
    }

    public static function actor(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<string>
     */
    private static function roleLabels(Model $record): array
    {
        if (! $record instanceof User) {
            return [];
        }

        return array_values($record->roles
            ->map(static fn (mixed $role): string => $role instanceof Role ? $role->displayName() : '')
            ->filter()
            ->all());
    }
}
