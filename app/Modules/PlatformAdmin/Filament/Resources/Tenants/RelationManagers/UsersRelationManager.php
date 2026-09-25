<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers;

use App\Modules\Access\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Users of the viewed tenant, read-only (ADR-0043). No write actions: user
 * management belongs to the tenant's owners; support uses impersonation.
 * 2FA is shown as enabled or not, never a secret.
 *
 * Same scope handling as InvitationsRelationManager. Role assignments are
 * team-scoped (team = tenant) and the team follows the TenantContext, so they
 * are read inside the user's own tenant context.
 */
final class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

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
            ->emptyStateHeading(__('platform.tenants.users.empty'));
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
