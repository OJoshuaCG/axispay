<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\RelationManagers;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Actions\ResendInvitation;
use App\Modules\Identity\Actions\RevokeInvitation;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Actions\InviteTenantOwner;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Invitations of the viewed tenant (ADR-0043): list, invite an owner,
 * resend (pending or expired) and revoke (pending). Presentation only; every
 * change goes through an Action, which authorizes the platform admin itself.
 *
 * The admin panel has no tenant context, so the query drops the fail-closed
 * tenant scope and keeps the relation's `tenant_id = owner` constraint. The
 * PlatformAdmin module is on the scope-bypass whitelist (ADR-0031); another
 * tenant's invitation is never loaded, so it cannot be acted on.
 */
final class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('platform.tenants.invitations.title');
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
            ->modelLabel(__('platform.tenants.invitations.singular'))
            ->pluralModelLabel(__('platform.tenants.invitations.plural'))
            ->columns([
                TextColumn::make('email')
                    ->label(__('platform.tenants.invitations.fields.email'))
                    ->formatStateUsing(static fn (string $state): ?string => PlatformPii::email($state))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('role_name')
                    ->label(__('platform.tenants.invitations.fields.role'))
                    ->formatStateUsing(static fn (string $state): string => SystemRole::tryFrom($state)?->label() ?? $state),
                TextColumn::make('status')
                    ->label(__('platform.tenants.invitations.fields.status'))
                    ->badge()
                    ->state(static fn (UserInvitation $record): string => $record->status()->label())
                    ->color(static fn (UserInvitation $record): string => $record->status()->color()),
                TextColumn::make('created_at')->label(__('platform.tenants.invitations.fields.invited_at'))->dateTime()->sortable(),
                TextColumn::make('expires_at')->label(__('platform.tenants.invitations.fields.expires_at'))->dateTime()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('platform.tenants.invitations.empty'))
            ->headerActions([$this->inviteOwnerAction()])
            ->recordActions([$this->resendAction(), $this->revokeAction()]);
    }

    private function tenant(): Tenant
    {
        $owner = $this->getOwnerRecord();

        abort_unless($owner instanceof Tenant, 404);

        return $owner;
    }

    private function inviteOwnerAction(): Action
    {
        return Action::make('inviteOwner')
            ->label(__('platform.tenants.invitations.actions.invite_owner'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => PlatformActor::current()->can('sendInvitations', $this->tenant()))
            ->modalDescription(__('platform.tenants.invitations.actions.invite_owner_help'))
            ->schema([
                TextInput::make('email')
                    ->label(__('platform.tenants.invitations.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(254),
            ])
            ->action(function (array $data, Action $action): void {
                $email = is_string($data['email'] ?? null) ? $data['email'] : '';

                try {
                    app(InviteTenantOwner::class)->handle(PlatformActor::current(), $this->tenant(), $email);
                } catch (EmailNotAvailableException|InvitationNotAllowedException $e) {
                    self::failure($e);
                    $action->halt();
                }

                Notification::make()->success()->title(__('platform.tenants.invitations.notifications.invited'))->send();
            });
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('platform.tenants.invitations.actions.resend'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (?UserInvitation $record): bool => $record?->status()->canBeResent() === true
                && PlatformActor::current()->can('sendInvitations', $this->tenant()))
            ->requiresConfirmation()
            ->modalDescription(__('platform.tenants.invitations.actions.resend_confirm'))
            ->action(static function (UserInvitation $record, Action $action): void {
                try {
                    app(ResendInvitation::class)->handle(PlatformActor::current(), $record);
                } catch (InvitationNotPendingException|EmailNotAvailableException|InvitationNotAllowedException $e) {
                    self::failure($e);
                    $action->halt();
                }

                Notification::make()->success()->title(__('platform.tenants.invitations.notifications.resent'))->send();
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('platform.tenants.invitations.actions.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (?UserInvitation $record): bool => $record?->status()->canBeRevoked() === true
                && PlatformActor::current()->can('revokeInvitations', $this->tenant()))
            ->requiresConfirmation()
            ->modalDescription(__('platform.tenants.invitations.actions.revoke_confirm'))
            ->action(static function (UserInvitation $record, Action $action): void {
                try {
                    app(RevokeInvitation::class)->handle(PlatformActor::current(), $record);
                } catch (InvitationNotPendingException $e) {
                    self::failure($e);
                    $action->halt();
                }

                Notification::make()->success()->title(__('platform.tenants.invitations.notifications.revoked'))->send();
            });
    }

    private static function failure(InvitationNotPendingException|EmailNotAvailableException|InvitationNotAllowedException $e): void
    {
        $key = match (true) {
            $e instanceof InvitationNotPendingException => 'not_pending',
            $e instanceof EmailNotAvailableException => 'email_not_available',
            $e->reason === 'throttled' => 'throttled',
            default => 'not_allowed',
        };

        Notification::make()->danger()->title(__('platform.tenants.invitations.errors.'.$key))->send();
    }
}
