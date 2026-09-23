<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use Illuminate\Contracts\Session\Session;

/**
 * Re-authentication window for sensitive actions (plan 17.3): after the user
 * confirms their password or 2FA code, sensitive actions are allowed for
 * auth.password_timeout seconds (10 minutes). Uses Laravel's standard
 * `auth.password_confirmed_at` session key, so the framework's
 * `password.confirm` middleware agrees with it.
 *
 * An impersonation session can never be re-authenticated (plan 17.4).
 */
final readonly class ReauthenticationWindow
{
    public const string SESSION_KEY = 'auth.password_confirmed_at';

    public function __construct(
        private Session $session,
        private ImpersonationState $impersonation,
    ) {}

    public function isConfirmed(): bool
    {
        if ($this->impersonation->isActive()) {
            return false;
        }

        $confirmedAt = $this->session->get(self::SESSION_KEY);

        return is_int($confirmedAt) && (now()->getTimestamp() - $confirmedAt) < $this->timeout();
    }

    public function confirm(): void
    {
        $this->session->put(self::SESSION_KEY, now()->getTimestamp());
    }

    public function ensureConfirmed(): void
    {
        if (! $this->isConfirmed()) {
            throw new ReauthenticationRequiredException;
        }
    }

    private function timeout(): int
    {
        $timeout = config('auth.password_timeout', 600);

        return is_int($timeout) ? $timeout : 600;
    }
}
