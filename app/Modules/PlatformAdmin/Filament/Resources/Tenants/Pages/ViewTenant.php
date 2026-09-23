<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Actions\StartImpersonation;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\Tenancy\Actions\ChangeTenantStatus;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantStatusTransitionException;
use App\Modules\Tenancy\Exceptions\TenantCloseNotConfirmedException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

final class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
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

    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('platform.tenants.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
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
