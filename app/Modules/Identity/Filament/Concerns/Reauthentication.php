<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Concerns;

use App\Modules\Identity\Actions\Reauthenticate;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Support\Filament\Forms\PasswordField;
use Closure;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Filament glue for re-authentication (plan 17.3). Sensitive actions add
 * field() to their modal; it only appears when the 10-minute window is closed.
 * confirm() hands the value to the Reauthenticate action, which verifies it,
 * audits the attempt and opens the window. The domain action then re-checks
 * the window itself, so the UI can never skip it.
 */
final class Reauthentication
{
    public const string FIELD = 'current_password';

    /**
     * @param  (Closure(Get): bool)|null  $when  extra condition (e.g. only for sensitive roles)
     */
    public static function field(?Closure $when = null): PasswordField
    {
        return PasswordField::make(self::FIELD)
            ->forCurrentPassword()
            ->label(__('identity.reauthentication.field'))
            ->helperText(__('identity.reauthentication.help'))
            ->required()
            ->validationMessages(['required' => __('identity.reauthentication.required')])
            ->visible(static fn (Get $get): bool => ! app(ReauthenticationWindow::class)->isConfirmed() && ($when === null || $when($get)))
            ->dehydrated();
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws ValidationException
     */
    public static function confirm(#[SensitiveParameter] array $data): void
    {
        if (app(ReauthenticationWindow::class)->isConfirmed()) {
            return;
        }

        $user = Filament::auth()->user();
        $secret = $data[self::FIELD] ?? '';

        if (! $user instanceof User && ! $user instanceof PlatformAdmin) {
            return;
        }

        app(Reauthenticate::class)->handle($user, is_string($secret) ? $secret : '', 'mountedActions.0.data.'.self::FIELD);
    }
}
