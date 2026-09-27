<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\Identity\Actions\ResendInvitation;
use App\Modules\Identity\Enums\InvitationStatus;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserInvitation;
use App\Modules\PlatformAdmin\Enums\TenantOwnershipState;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\ImpersonationUi;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use App\Modules\PlatformAdmin\Services\TenantOwnership;
use App\Modules\ProviderEvents\Services\ProviderEventActivity;
use App\Modules\Tenancy\Actions\ChangeTenantStatus;
use App\Modules\Tenancy\Actions\InviteTenantOwner;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantStatusTransitionException;
use App\Modules\Tenancy\Exceptions\TenantCloseNotConfirmedException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

final class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    /** Per-request memo of the ownership state (several closures read it). */
    private ?TenantOwnershipState $ownershipState = null;

    /**
     * Per-request memo of the tenant's connections, both modes.
     *
     * @var list<GatewayConnection>|null
     */
    private ?array $gatewayConnections = null;

    /**
     * Per-request memo: latest provider event per connection ID.
     *
     * @var array<string, CarbonImmutable>|null
     */
    private ?array $lastEvents = null;

    /** Per-request memo; false = looked up, none found. */
    private UserInvitation|false|null $ownerInvitation = null;

    /**
     * Per-request memo of the users "View as user" can pick.
     *
     * @var array<string, string>|null
     */
    private ?array $impersonableUsers = null;

    /**
     * The profile, with a warning above it while the tenant has no active
     * owner (plan 17.2, ADR-0045).
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            $this->ownershipCallout(),
            TenantResource::profileSection(),
            $this->gatewaySection(),
        ]);
    }

    /**
     * Read-only view of the tenant's gateway connections, both modes (Phase
     * 2). Never secrets: the model hides the credential columns and only
     * status fields are listed. Read through the whitelisted resolver.
     * "Last Stripe event" shows when the connection last received a webhook
     * (UTC, like the rest of the platform panel), with a warning badge when
     * a connection that can charge has gone silent (ADR-0050).
     */
    private function gatewaySection(): Section
    {
        return Section::make(__('gateways.admin.heading'))
            ->key('gateway')
            ->schema([
                RepeatableEntry::make('gateway_connections')
                    ->hiddenLabel()
                    ->state(fn (): array => $this->gatewayConnections())
                    ->placeholder(__('gateways.admin.empty'))
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('livemode')
                            ->label(__('gateways.admin.mode'))
                            ->formatStateUsing(static fn (mixed $state): string => __('gateways.mode.'.($state === true ? 'live' : 'test'))),
                        TextEntry::make('connection_method')
                            ->label(__('gateways.fields.method'))
                            ->formatStateUsing(static fn (mixed $state): string => $state instanceof ConnectionMethod ? $state->label() : '—'),
                        TextEntry::make('status')
                            ->label(__('gateways.fields.status'))
                            ->badge()
                            ->formatStateUsing(static fn (mixed $state): string => $state instanceof ConnectionStatus ? $state->label() : '—')
                            ->color(static fn (mixed $state): string => $state instanceof ConnectionStatus ? $state->color() : 'gray'),
                        TextEntry::make('country')->label(__('gateways.fields.country'))->placeholder('—'),
                        TextEntry::make('provider_account_id')->label(__('gateways.fields.account'))->fontFamily(FontFamily::Mono)->placeholder('—')->copyable(),
                        IconEntry::make('charges_enabled')->label(__('gateways.fields.charges_enabled'))->boolean(),
                        TextEntry::make('last_synced_at')->label(__('gateways.fields.last_synced_at'))->dateTime()->placeholder('—'),
                        TextEntry::make('disconnected_at')->label(__('gateways.admin.disconnected_at'))->dateTime()->placeholder('—'),
                        TextEntry::make('last_provider_event_at')
                            ->label(__('gateways.admin.last_event'))
                            ->state(fn (GatewayConnection $record): ?CarbonImmutable => $this->lastEvents()[$record->id] ?? null)
                            ->formatStateUsing(static fn (mixed $state): string => $state instanceof CarbonImmutable
                                ? __('gateways.admin.last_event_value', ['relative' => $state->diffForHumans(), 'date' => $state->utc()->isoFormat('lll')])
                                : '—')
                            ->placeholder(__('gateways.admin.no_events')),
                        TextEntry::make('provider_events_silent')
                            ->label(__('gateways.admin.events_health'))
                            ->state(static fn (): string => __('gateways.admin.silent', ['days' => ProviderEventActivity::silenceDays()]))
                            ->badge()
                            ->color('warning')
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->tooltip(__('gateways.admin.silent_help'))
                            ->visible(fn (GatewayConnection $record): bool => app(ProviderEventActivity::class)->isSilent($record, $this->lastEvents()[$record->id] ?? null)),
                    ]),
            ])
            ->columnSpanFull();
    }

    /**
     * @return list<GatewayConnection>
     */
    private function gatewayConnections(): array
    {
        return $this->gatewayConnections ??= array_values(app(GatewayConnectionResolver::class)->ofTenant($this->tenant()->id)->all());
    }

    /**
     * One grouped query for every connection of the tenant.
     *
     * @return array<string, CarbonImmutable>
     */
    private function lastEvents(): array
    {
        return $this->lastEvents ??= app(ProviderEventActivity::class)->lastReceivedAtByConnection(
            $this->tenant()->id,
            array_map(static fn (GatewayConnection $connection): string => $connection->id, $this->gatewayConnections()),
        );
    }

    /** Owner invited, resent or granted on this page or its relation managers. */
    #[On('tenant-ownership-changed')]
    public function refreshOwnership(): void
    {
        $this->ownershipState = null;
        $this->ownerInvitation = null;
        $this->impersonableUsers = null;
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

    /**
     * Plan 17.4: shown to superadmins for every tenant whose status allows
     * panel access (pending_onboarding included), disabled with the reason
     * as tooltip while the tenant has no active user. The Users tab offers
     * the same per user.
     */
    private function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label(__('platform.impersonation.action'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (): bool => PlatformActor::current()->can('impersonateUsers', $this->tenant()))
            ->disabled(fn (): bool => $this->impersonableUsers() === [])
            ->tooltip(fn (): ?string => $this->impersonableUsers() === [] ? $this->noImpersonableUsersMessage() : null)
            ->modalDescription(__('platform.impersonation.description'))
            ->schema([
                Select::make('user_id')
                    ->label(__('platform.impersonation.user'))
                    ->options(fn (): array => $this->impersonableUsers())
                    ->searchable()
                    ->required(),
                ImpersonationUi::reasonField(),
            ])
            ->action(function (array $data, Action $action): void {
                $user = $this->tenantUsers()->find(is_string($data['user_id'] ?? null) ? $data['user_id'] : '');

                if (! $user instanceof User) {
                    $action->halt();

                    return;
                }

                ImpersonationUi::start($user, $data['reason'] ?? null, $action, $this);
            });
    }

    private function noImpersonableUsersMessage(): string
    {
        return __('platform.impersonation.no_active_users');
    }

    /**
     * @return array<string, string>
     */
    private function impersonableUsers(): array
    {
        if ($this->impersonableUsers !== null) {
            return $this->impersonableUsers;
        }

        $options = [];

        foreach ($this->tenantUsers()->whereNull('disabled_at')->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name.' <'.$user->email.'>';
        }

        return $this->impersonableUsers = $options;
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
