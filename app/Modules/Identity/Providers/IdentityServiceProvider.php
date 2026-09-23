<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Auth\TenantUserProvider;
use App\Modules\Identity\Auth\UpdateLastLogin;
use App\Modules\Identity\Console\DevResetTwoFactorCommand;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class IdentityServiceProvider extends ServiceProvider
{
    /**
     * Abilities an impersonation session may use (plan 17.4: read-only).
     */
    private const array READ_ONLY_ABILITIES = ['viewAny', 'view'];

    public function boot(): void
    {
        Auth::provider('tenant_users', static function (Application $app, array $config): TenantUserProvider {
            $model = $config['model'] ?? User::class;

            return new TenantUserProvider($app->make('hash'), is_string($model) ? $model : User::class);
        });

        Event::listen(Login::class, UpdateLastLogin::class);

        if ($this->app->runningInConsole()) {
            $this->commands([DevResetTwoFactorCommand::class]);
        }

        // Plan 17.4: an impersonation session is read-only. Every ability
        // other than viewing is denied before any policy runs.
        Gate::before(static function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User || in_array($ability, self::READ_ONLY_ABILITIES, true)) {
                return null;
            }

            $request = request();

            if (! $request->hasSession()) {
                return null;
            }

            return (new ImpersonationState($request->session()))->isActive() ? false : null;
        });

        // Plan 17.3: at least 12 characters and not in known breaches.
        Password::defaults(static function (): Password {
            $min = config('paylink.passwords.min_length', 12);
            $rule = Password::min(is_int($min) ? $min : 12);

            return config('paylink.passwords.check_uncompromised', true) === true ? $rule->uncompromised() : $rule;
        });
    }
}
