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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Operator recovery of a forgotten password (ADR-0041) for a platform admin or
 * tenant user. The new password follows the same policy as account creation
 * (`Password::defaults()`: minimum length and, when enabled, the breach
 * check). The account is signed out everywhere (plan 17.3: other sessions are
 * invalidated on a password change) and its sign-in throttle is cleared. 2FA
 * is not touched. The password never reaches the audit entry.
 */
final readonly class ResetPassword
{
    public function __construct(
        private AccountLock $lock,
        private SessionRevoker $sessions,
        private LoginThrottle $throttle,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException when the password breaks the policy
     */
    public function handle(AccountRecoveryRequest $request, #[SensitiveParameter] string $password): AccountRecoveryResult
    {
        self::validate($password);

        $result = $this->lock->run($request->account, function (User|PlatformAdmin $account) use ($request, $password): AccountRecoveryResult {
            $account->forceFill(['password' => $password]);
            $account->setRememberToken(Str::random(60));
            $account->save();

            $revoked = $this->sessions->revokeAll($account);

            $this->audit->record(
                AuditAction::PasswordReset,
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

        $this->throttle->clear($request->account->email);

        return $result;
    }

    /**
     * @throws ValidationException
     */
    public static function validate(#[SensitiveParameter] string $password): void
    {
        Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]],
        )->validate();
    }
}
