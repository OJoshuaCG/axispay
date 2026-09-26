<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\Identity\Actions\ResendInvitation;
use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Actions\StartImpersonation;
use App\Modules\PlatformAdmin\Enums\TenantOwnershipState;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use App\Modules\PlatformAdmin\Services\TenantOwnership;
use App\Modules\Tenancy\Actions\ChangeTenantStatus;
use App\Modules\Tenancy\Actions\InviteTenantOwner;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantStatusTransitionException;
use App\Modules\Tenancy\Exceptions\TenantCloseNotConfirmedException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

final class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    /** Per-request memo of the ownership state (several closures read it). */
    private ?TenantOwnershipState $ownershipState = null;

    /** Per-request memo; false = looked up, none found. */
    private UserInvitation|false|null $ownerInvitation = null;

    /**
     * The profile, with a warning above it while the tenant has no active
     * owner (plan 17.2, ADR-0045).
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            $this->ownershipCallout(),
            TenantResource::profileSection(),
        ]);
    }

    /** Owner invited, resent or granted on this page or its relation managers. */
    #[On('tenant-ownership-changed')]
    public function refreshOwnership(): void
    {
        $this->ownershipState = null;
        $this->ownerInvitation = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            $this->changeStatusAction(),
            $this->impersonateAction(),
        ];
    }

    private function tenant(): Tenant
    {
        $record = $this->getRecord();

        abort_unless($record instanceof Tenant, 404);

        return $record;
    }

    private function ownershipState(): TenantOwnershipState
    {
        return $this->ownershipState ??= app(TenantOwnership::class)->stateOf($this->tenant());
    }

    private function ownerInvitation(): ?UserInvitation
    {
        $this->ownerInvitation ??= app(TenantOwnership::class)->latestOpenOwnerInvitation($this->tenant()) ?? false;

        return $this->ownerInvitation === false ? null : $this->ownerInvitation;
    }

    private function ownershipCallout(): Callout
    {
        return Callout::make(__('platform.tenants.ownership.callout.heading'))
            // A key lets Livewire mount the callout's actions.
            ->key('ownership')
            ->warning()
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->description(fn (): string => $this->ownershipDescription())
            ->visible(fn (): bool => $this->ownershipState() !== TenantOwnershipState::Active)
            ->actions([$this->inviteOwnerAction(), $this->resendOwnerInvitationAction()])
            ->columnSpanFull();
    }

    private function ownershipDescription(): string
    {
        $invitation = $this->ownerInvitation();
        $lines = [__('platform.tenants.ownership.callout.body')];

        if ($invitation === null) {
            $lines[] = __('platform.tenants.ownership.callout.no_invitation');
        } else {
            $key = $invitation->status() === InvitationStatus::Pending ? 'pending_invitation' : 'expired_invitation';
            $lines[] = __('platform.tenants.ownership.callout.'.$key, [
                'email' => PlatformPii::email($invitation->email) ?? '—',
                'date' => $invitation->expires_at->isoFormat('LLL'),
            ]);
        }

        $lines[] = __('platform.tenants.ownership.callout.recover');

        return implode(' ', $lines);
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
                try {
                    app(InviteTenantOwner::class)->handle(PlatformActor::current(), $this->tenant(), is_string($data['email'] ?? null) ? $data['email'] : '');
                } catch (EmailNotAvailableException|InvitationNotAllowedException $e) {
                    self::invitationFailure($e);
                    $action->halt();
                }

                $this->ownershipChanged();
                Notification::make()->success()->title(__('platform.tenants.invitations.notifications.invited'))->send();
            });
    }

    private function resendOwnerInvitationAction(): Action
    {
        return Action::make('resendOwnerInvitation')
            ->label(__('platform.tenants.ownership.actions.resend'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => $this->ownerInvitation()?->status()->canBeResent() === true
                && PlatformActor::current()->can('sendInvitations', $this->tenant()))
            ->requiresConfirmation()
            ->modalDescription(__('platform.tenants.invitations.actions.resend_confirm'))
            ->action(function (Action $action): void {
                $invitation = $this->ownerInvitation();

                if ($invitation === null) {
                    $action->halt();

                    return;
                }

                try {
                    app(ResendInvitation::class)->handle(PlatformActor::current(), $invitation);
                } catch (InvitationNotPendingException|EmailNotAvailableException|InvitationNotAllowedException $e) {
                    self::invitationFailure($e);
                    $action->halt();
                }

                $this->ownershipChanged();
                Notification::make()->success()->title(__('platform.tenants.invitations.notifications.resent'))->send();
            });
    }

    /** Refreshes the warning here and the lists in the relation managers. */
    private function ownershipChanged(): void
    {
        $this->refreshOwnership();
        $this->dispatch('tenant-ownership-changed');
    }

    private static function invitationFailure(InvitationNotPendingException|EmailNotAvailableException|InvitationNotAllowedException $e): void
    {
        $key = match (true) {
            $e instanceof InvitationNotPendingException => 'not_pending',
            $e instanceof EmailNotAvailableException => 'email_not_available',
            $e->reason === 'throttled' => 'throttled',
            default => 'not_allowed',
        };

        Notification::make()->danger()->title(__('platform.tenants.invitations.errors.'.$key))->send();
    }

    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('platform.tenants.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            // One primary action per header: Edit (ADR-0044).
            ->color('gray')
            ->authorize('changeStatus')
            ->schema([
                Select::make('status')
                    ->label(__('platform.tenants.fields.new_status'))
                    ->options(fn (): array => collect($this->tenant()->status->allowedTransitions())
                        ->mapWithKeys(static fn (TenantStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->required()
                    ->live(),
                Textarea::make('reason')
                    ->label(__('platform.tenants.fields.status_reason'))
                    ->required()
                    ->maxLength(500),
                TextInput::make('close_confirmation')
                    ->label(__('platform.tenants.fields.close_confirmation', ['name' => $this->tenant()->display_name]))
                    ->visible(static fn (Get $get): bool => $get('status') === TenantStatus::Closed->value)
                    ->required(static fn (Get $get): bool => $get('status') === TenantStatus::Closed->value),
            ])
            ->action(function (array $data, Action $action): void {
                $status = TenantStatus::from(is_string($data['status'] ?? null) ? $data['status'] : '');
                $confirmation = $data['close_confirmation'] ?? null;

                try {
                    app(ChangeTenantStatus::class)->handle(PlatformActor::current(), $this->tenant(), new ChangeTenantStatusData(
                        status: $status,
                        reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
                        closeConfirmation: is_string($confirmation) ? $confirmation : null,
                    ));
                } catch (TenantCloseNotConfirmedException|InvalidTenantStatusTransitionException) {
                    Notification::make()->danger()->title(__('platform.tenants.errors.status_change'))->send();
                    $action->halt();
                }

                $this->refreshFormData(['status', 'status_reason', 'status_changed_at']);
                Notification::make()->success()->title(__('platform.tenants.notifications.status_changed'))->send();
            });
    }

    private function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label(__('platform.impersonation.action'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (): bool => PlatformActor::current()->isSuperadmin() && $this->tenant()->status->allowsPanelAccess())
            ->modalDescription(__('platform.impersonation.description'))
            ->schema([
                Select::make('user_id')
                    ->label(__('platform.impersonation.user'))
                    ->options(fn (): array => $this->impersonableUsers())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')
                    ->label(__('platform.impersonation.reason'))
                    ->required()
                    ->maxLength(500),
            ])
            ->action(function (array $data, Action $action): void {
                $user = $this->tenantUsers()->find(is_string($data['user_id'] ?? null) ? $data['user_id'] : '');

                if (! $user instanceof User) {
                    $action->halt();

                    return;
                }

                try {
                    $started = app(StartImpersonation::class)->handle(PlatformActor::current(), $user, is_string($data['reason'] ?? null) ? $data['reason'] : '');
                } catch (ImpersonationNotAllowedException) {
                    Notification::make()->danger()->title(__('platform.impersonation.errors.not_allowed'))->send();
                    $action->halt();

                    return;
                }

                $this->redirect($started->handoffUrl);
            });
    }

    /**
     * @return array<string, string>
     */
    private function impersonableUsers(): array
    {
        $options = [];

        foreach ($this->tenantUsers()->whereNull('disabled_at')->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name.' <'.$user->email.'>';
        }

        return $options;
    }

    /**
     * Users of the viewed tenant, read without a tenant context. Allowed here:
     * the PlatformAdmin module is on the scope-bypass whitelist (plan 6.5).
     *
     * @return Builder<User>
     */
    private function tenantUsers(): Builder
    {
        return User::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant()->id);
    }
}
