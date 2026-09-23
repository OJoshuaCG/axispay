<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Pages;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in page of both panels. On top of Filament's per-IP limit, failed
 * attempts are throttled per account (plan 17.3, ADR-0034): the key is the
 * SHA-256 of the normalized e-mail, so spreading attempts over many IPs does
 * not help, and neither the key nor the audit entry contains the e-mail.
 * Failed 2FA codes count as failed attempts too.
 */
final class Login extends BaseLogin
{
    public const int MAX_ATTEMPTS_PER_ACCOUNT = 5;

    public const int DECAY_SECONDS = 900;

    public function authenticate(): ?LoginResponse
    {
        $key = $this->accountThrottleKey();

        if ($key !== null && RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_ACCOUNT)) {
            app(AuditLogger::class)->record(AuditAction::LoginThrottled, changes: [
                'guard' => Filament::getAuthGuard(),
                'login_hash' => $this->loginHash(),
            ], platform: true, actor: Actor::system());

            throw ValidationException::withMessages([
                'data.email' => __('identity.login.throttled', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $e) {
            if ($key !== null) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            throw $e;
        }

        if ($response !== null && $key !== null) {
            RateLimiter::clear($key);
        }

        return $response;
    }

    public static function throttleKeyFor(string $panelId, string $email): string
    {
        return 'login-account:'.$panelId.':'.hash('sha256', mb_strtolower(trim($email)));
    }

    private function accountThrottleKey(): ?string
    {
        $email = $this->data['email'] ?? null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return self::throttleKeyFor(Filament::getCurrentOrDefaultPanel()?->getId() ?? 'unknown', $email);
    }

    private function loginHash(): ?string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) ? hash('sha256', mb_strtolower(trim($email))) : null;
    }
}
