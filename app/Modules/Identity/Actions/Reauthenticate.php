<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Confirms the identity of the signed-in user with their password or current
 * 2FA code (plan 17.3) and opens the 10-minute re-authentication window.
 * Rate limited; every attempt is audited. Impossible while impersonating
 * (plan 17.4): the platform admin does not know the user's secrets and must
 * never perform sensitive actions as them.
 */
final readonly class Reauthenticate
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private ReauthenticationWindow $window,
        private ImpersonationState $impersonation,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User|PlatformAdmin $user, #[SensitiveParameter] string $secret, string $field = 'current_password'): void
    {
        if ($this->impersonation->isActive()) {
            throw new ReauthenticationRequiredException;
        }

        $key = 'reauthenticate:'.$user::class.':'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([$field => __('identity.reauthentication.throttled', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (! $this->verify($user, $secret)) {
            RateLimiter::hit($key);
            $this->record($user, AuditAction::ReauthenticationFailed);

            throw ValidationException::withMessages([$field => __('identity.reauthentication.failed')]);
        }

        RateLimiter::clear($key);
        $this->window->confirm();
        $this->record($user, AuditAction::ReauthenticationConfirmed);
    }

    private function verify(User|PlatformAdmin $user, #[SensitiveParameter] string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        if (Hash::check($secret, $user->password)) {
            return true;
        }

        $twoFactorSecret = $user->getAppAuthenticationSecret();

        return $twoFactorSecret !== null
            && preg_match('/^\d{6}$/', $secret) === 1
            && AppAuthentication::make()->verifyCode($secret, $twoFactorSecret, shouldPreventCodeReuse: true);
    }

    private function record(User|PlatformAdmin $user, AuditAction $action): void
    {
        if ($user instanceof User) {
            $this->audit->record($action, $user, tenantId: $user->tenant_id);

            return;
        }

        $this->audit->record($action, $user, platform: true);
    }
}
