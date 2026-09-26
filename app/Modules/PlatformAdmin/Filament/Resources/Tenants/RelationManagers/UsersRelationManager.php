<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers;

use App\Modules\Access\Models\Role;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Actions\PromoteToOwner;
use App\Modules\PlatformAdmin\Exceptions\OwnerPromotionNotAllowedException;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\PlatformAdmin\Services\TenantOwnership;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Users of the viewed tenant (ADR-0043). User management belongs to the
 * tenant's owners and support uses impersonation; the one write action is
 * "Make owner" (ADR-0045): a superadmin grants the owner role to an active
 * user, with a reason and a fresh re-authentication, through PromoteToOwner.
 * 2FA is shown as enabled or not, never a secret.
 *
 * Same scope handling as InvitationsRelationManager: the relation keeps its
 * `tenant_id = owner` constraint, so another tenant's user is never loaded
 * and cannot be acted on. Role assignments are team-scoped (team = tenant)
 * and the team follows the TenantContext, so they are read inside the
 * user's own tenant context.
 */
final class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    /**
     * Per-request memo of the tenant's owner IDs (one query per render).
     *
     * @var list<string>|null
     */
    private ?array $ownerIds = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('platform.tenants.users.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof PlatformAdmin && $ownerRecord instanceof Tenant && $admin->can('viewMembers', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withoutGlobalScope(TenantScope::class))
            ->modelLabel(__('platform.tenants.users.singular'))
            ->pluralModelLabel(__('platform.tenants.users.plural'))
            ->columns([
                TextColumn::make('name')->label(__('identity.users.fields.name'))->searchable()->sortable()->wrap(),
                TextColumn::make('email')
                    ->label(__('identity.users.fields.email'))
                    ->formatStateUsing(static fn (string $state): ?string => PlatformPii::email($state))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('roles')
                    ->label(__('identity.users.fields.roles'))
                    ->badge()
                    ->state(static fn (User $record): array => self::roleLabels($record)),
                TextColumn::make('status')
                    ->label(__('identity.users.fields.status'))
                    ->badge()
                    ->state(static fn (User $record): string => $record->isDisabled() ? __('identity.users.status.disabled') : __('identity.users.status.active'))
                    ->color(static fn (User $record): string => $record->isDisabled() ? 'gray' : 'success'),
                IconColumn::make('two_factor_confirmed_at')
                    ->label(__('identity.users.fields.two_factor'))
                    ->boolean()
                    ->state(static fn (User $record): bool => $record->hasTwoFactorEnabled()),
                TextColumn::make('last_login_at')
                    ->label(__('identity.users.fields.last_login_at'))
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([$this->promoteOwnerAction()])
            ->emptyStateHeading(__('platform.tenants.users.empty'));
    }

    /** An owner was granted or invited elsewhere on the page. */
    #[On('tenant-ownership-changed')]
    public function refreshAfterOwnershipChange(): void
    {
        $this->ownerIds = null;
    }

    private function tenant(): Tenant
    {
        $owner = $this->getOwnerRecord();

        abort_unless($owner instanceof Tenant, 404);

        return $owner;
    }

    private function promoteOwnerAction(): Action
    {
        return Action::make('promoteOwner')
            ->label(__('platform.tenants.users.actions.promote_owner'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('warning')
            ->visible(fn (?User $record): bool => $record instanceof User
                && ! $record->isDisabled()
                && PlatformActor::current()->can('promoteOwner', $this->tenant())
                && ! in_array($record->id, $this->ownerIds(), true))
            ->modalHeading(__('platform.tenants.users.actions.promote_owner_heading'))
            ->modalDescription(__('platform.tenants.users.actions.promote_owner_help'))
            ->modalSubmitActionLabel(__('platform.tenants.users.actions.promote_owner_submit'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('platform.tenants.users.fields.promotion_reason'))
                    ->helperText(__('platform.tenants.users.fields.promotion_reason_help'))
                    ->required()
                    ->minLength(PromoteToOwner::MIN_REASON_LENGTH)
                    ->maxLength(500),
                Reauthentication::field(),
            ])
            ->action(function (array $data, User $record, Action $action): void {
                Reauthentication::confirm($data);

                try {
                    app(PromoteToOwner::class)->handle(
                        PlatformActor::current(),
                        $this->tenant(),
                        $record,
                        is_string($data['reason'] ?? null) ? $data['reason'] : '',
                    );
                } catch (OwnerPromotionNotAllowedException $e) {
                    Notification::make()->danger()->title(__('platform.tenants.users.errors.'.$e->reason))->send();
                    $action->halt();
                } catch (ReauthenticationRequiredException) {
                    Notification::make()->danger()->title(__('platform.tenants.users.errors.reauthentication'))->send();
                    $action->halt();
                }

                $this->ownerIds = null;
                $this->dispatch('tenant-ownership-changed');
                Notification::make()->success()->title(__('platform.tenants.users.notifications.promoted'))->send();
            });
    }

    /**
     * @return list<string>
     */
    private function ownerIds(): array
    {
        return $this->ownerIds ??= app(TenantOwnership::class)->ownerIds($this->tenant());
    }

    /**
     * @return list<string>
     */
    private static function roleLabels(User $user): array
    {
        return app(TenantContext::class)->runAsTenant($user->tenant_id, false, static fn (): array => array_values($user->roles()->get()
            ->map(static fn (mixed $role): string => $role instanceof Role ? $role->displayName() : '')
            ->filter()
            ->all()));
    }
}
