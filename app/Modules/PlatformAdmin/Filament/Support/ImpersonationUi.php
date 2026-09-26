<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Support;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Actions\StartImpersonation;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Filament glue for "View as user" (plan 17.4), shared by the tenant view
 * page header (pick a user) and the row action of its Users tab (that user).
 * Both go through StartImpersonation, which re-checks the superadmin role,
 * the reason, the user and the tenant status; the visibility rule of both
 * buttons is TenantPolicy::impersonateUsers.
 */
final class ImpersonationUi
{
    public static function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label(__('platform.impersonation.reason'))
            ->required()
            ->maxLength(500);
    }

    /**
     * Starts the session and sends the admin to the single-use hand-off link
     * on the app host; halts the action with a notification when refused.
     */
    public static function start(User $user, mixed $reason, Action $action, Component $livewire): void
    {
        try {
            $started = app(StartImpersonation::class)->handle(PlatformActor::current(), $user, is_string($reason) ? $reason : '');
        } catch (ImpersonationNotAllowedException) {
            Notification::make()->danger()->title(__('platform.impersonation.errors.not_allowed'))->send();
            $action->halt();

            return;
        }

        $livewire->redirect($started->handoffUrl);
    }
}
