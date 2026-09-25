<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Data\AccountRecoveryRequest;
use App\Modules\Identity\Data\AccountRecoveryResult;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountLock;
use App\Modules\Identity\Services\LoginThrottle;
use App\Modules\Identity\Services\SessionRevoker;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Support\Str;

/**
 * Operator recovery of a lost authenticator (ADR-0041): removes the 2FA
 * secret and recovery codes of a platform admin or tenant user, signs the
 * account out everywhere (sessions and "remember me") and clears its sign-in
 * throttle. Where 2FA is mandatory, the next sign-in sends the account to the
 * 2FA set-up page. An account without 2FA is left untouched. The stored
 * secret is never decrypted, so this also works after an APP_KEY loss.
 */
final readonly class ResetTwoFactor
{
    public function __construct(
        private AccountLock $lock,
        private SessionRevoker $sessions,
        private LoginThrottle $throttle,
        private AuditLogger $audit,
    ) {}

    public function handle(AccountRecoveryRequest $request): AccountRecoveryResult
    {
        $result = $this->lock->run($request->account, function (User|PlatformAdmin $account) use ($request): AccountRecoveryResult {
            if (! self::hasTwoFactorData($account)) {
                return AccountRecoveryResult::unchanged();
            }

            $account->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            $account->setRememberToken(Str::random(60));
            $account->save();

            $revoked = $this->sessions->revokeAll($account);

            $this->audit->record(
                AuditAction::TwoFactorReset,
                $account,
                array_filter([
                    'source' => $request->source,
                    'reason' => $request->reason,
                    'sessions_revoked' => $revoked,
                ], static fn (mixed $value): bool => $value !== null),
                tenantId: $account instanceof User ? $account->tenant_id : null,
                platform: $account instanceof PlatformAdmin,
                actor: Actor::system(),
            );

            return new AccountRecoveryResult(true, $revoked);
        });

        if ($result->changed) {
            $this->throttle->clear($request->account->email);
        }

        return $result;
    }

    /**
     * Checks the stored (encrypted) values without decrypting them: after an
     * APP_KEY rotation without APP_PREVIOUS_KEYS they are unreadable, and that
     * is exactly when this reset is needed.
     */
    public static function hasTwoFactorData(User|PlatformAdmin $account): bool
    {
        return filled($account->getRawOriginal('two_factor_secret'))
            || filled($account->getRawOriginal('two_factor_recovery_codes'));
    }
}
