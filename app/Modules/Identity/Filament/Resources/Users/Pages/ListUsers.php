<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Resources\Users\Pages;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Identity\Actions\InviteUser;
use App\Modules\Identity\Data\InviteUserData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Resources\Users\UserResource;
use App\Modules\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('identity.users.actions.invite'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->authorize('invite', User::class)
                ->schema([
                    TextInput::make('email')
                        ->label(__('identity.users.fields.email'))
                        ->email()
                        ->required()
                        ->maxLength(254),
                    Select::make('role')
                        ->label(__('identity.users.fields.role'))
                        // Only roles the inviter could grant (RoleGrantGuard).
                        ->options(static fn (): array => app(RoleGrantGuard::class)->grantableRoleOptions(UserResource::actor()))
                        ->in(static fn (): array => array_keys(app(RoleGrantGuard::class)->grantableRoleOptions(UserResource::actor())))
                        ->required()
                        ->live(),
                    Reauthentication::field(static fn (Get $get): bool => SystemRole::tryFrom(is_string($get('role')) ? $get('role') : '')?->isSensitive() === true),
                ])
                ->action(static function (array $data, Action $action): void {
                    $role = SystemRole::from(is_string($data['role'] ?? null) ? $data['role'] : '');

                    if ($role->isSensitive()) {
                        Reauthentication::confirm($data);
                    }

                    $email = is_string($data['email'] ?? null) ? $data['email'] : '';

                    try {
                        app(InviteUser::class)->handle(new InviteUserData($email, $role), UserResource::actor());
                    } catch (EmailNotAvailableException) {
                        Notification::make()->danger()->title(__('identity.users.errors.email_not_available'))->send();
                        $action->halt();
                    } catch (InvitationNotAllowedException $e) {
                        Notification::make()->danger()->title(__('identity.users.errors.invitation_not_allowed'))->body($e->reason === 'throttled' ? (string) trans('identity.users.errors.invitation_throttled') : null)->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title(__('identity.users.notifications.invited'))->send();
                }),
        ];
    }
}
