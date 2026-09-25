<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Actions\ResetTwoFactor;
use App\Modules\Identity\Data\AccountRecoveryRequest;
use App\Modules\Identity\Filament\Pages\Login;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\LoginThrottle;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['session.driver' => 'database', 'session.table' => 'sessions']);
});

function recoverySession(User|PlatformAdmin $account): void
{
    DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(20)),
        'user_id' => $account->id,
        'payload' => '',
        'last_activity' => time(),
    ]);
}

function recoveryAudit(AuditAction $action): AuditLog
{
    return AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', $action->value)->sole();
}

it('resets the 2FA of a platform admin, audits it and signs the admin out everywhere', function (): void {
    $admin = platformAdmin();
    $admin->forceFill(['two_factor_recovery_codes' => ['code-a', 'code-b'], 'remember_token' => 'old-token'])->save();
    $other = platformAdmin();
    recoverySession($admin);
    recoverySession($admin);
    recoverySession($other);

    $result = app(ResetTwoFactor::class)->handle(new AccountRecoveryRequest($admin, 'Lost phone, ticket OPS-123'));

    $admin->refresh();
    expect($result->changed)->toBeTrue()
        ->and($result->sessionsRevoked)->toBe(2)
        ->and($admin->two_factor_secret)->toBeNull()
        ->and($admin->two_factor_recovery_codes)->toBeNull()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and($admin->getRememberToken())->not->toBe('old-token')
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);

    $entry = recoveryAudit(AuditAction::TwoFactorReset);
    expect($entry->tenant_id)->toBeNull()
        ->and($entry->actor_type->value)->toBe('system')
        ->and($entry->subject_id)->toBe($admin->id)
        ->and($entry->changes)->toBe(['source' => 'cli', 'reason' => 'Lost phone, ticket OPS-123', 'sessions_revoked' => 2]);
});

it('resets the 2FA of a tenant user in the tenant audit trail', function (): void {
    $user = tenantUser();

    app(ResetTwoFactor::class)->handle(new AccountRecoveryRequest($user, 'Lost authenticator app'));

    $fresh = app(TenantContext::class)->runAsTenant($user->tenant_id, false, static fn (): User => User::query()->findOrFail($user->id));
    expect($fresh->two_factor_secret)->toBeNull()
        ->and(recoveryAudit(AuditAction::TwoFactorReset)->tenant_id)->toBe($user->tenant_id)
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('leaves an account without 2FA untouched', function (): void {
    $admin = platformAdmin(twoFactor: false);
    recoverySession($admin);

    $result = app(ResetTwoFactor::class)->handle(new AccountRecoveryRequest($admin, 'Nothing to reset here'));

    expect($result->changed)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::TwoFactorReset->value)->exists())->toBeFalse();
});

it('reports that sessions cannot be revoked with a non-database session driver', function (): void {
    config(['session.driver' => 'array']);

    $result = app(ResetTwoFactor::class)->handle(new AccountRecoveryRequest(platformAdmin(), 'Lost phone, ticket OPS-9'));

    expect($result->changed)->toBeTrue()
        ->and($result->sessionsRevoked)->toBeNull()
        ->and(recoveryAudit(AuditAction::TwoFactorReset)->changes)->not->toHaveKey('sessions_revoked');
});

it('refuses a reason that is too short', function (): void {
    new AccountRecoveryRequest(platformAdmin(), 'short');
})->throws(InvalidArgumentException::class);

it('resets a password, audits it without the password and clears the sign-in throttle', function (): void {
    $user = tenantUser();
    recoverySession($user);

    foreach (LoginThrottle::PANELS as $panel) {
        RateLimiter::hit(Login::throttleKeyFor($panel, $user->email), Login::DECAY_SECONDS);
    }

    $result = app(ResetPassword::class)->handle(new AccountRecoveryRequest($user, 'Forgotten password, ticket OPS-7'), 'a-brand-new-passphrase');

    $fresh = app(TenantContext::class)->runAsTenant($user->tenant_id, false, static fn (): User => User::query()->findOrFail($user->id));
    $entry = recoveryAudit(AuditAction::PasswordReset);

    expect($result->sessionsRevoked)->toBe(1)
        ->and(Hash::check('a-brand-new-passphrase', $fresh->password))->toBeTrue()
        ->and($fresh->two_factor_secret)->not->toBeNull()
        ->and($entry->tenant_id)->toBe($user->tenant_id)
        ->and(array_keys($entry->changes ?? []))->toBe(['source', 'reason', 'sessions_revoked'])
        ->and(json_encode($entry->changes))->not->toContain('a-brand-new-passphrase');

    foreach (LoginThrottle::PANELS as $panel) {
        expect(RateLimiter::attempts(Login::throttleKeyFor($panel, $user->email)))->toBe(0);
    }
});

it('enforces the account creation password policy', function (): void {
    $admin = platformAdmin();

    expect(fn () => app(ResetPassword::class)->handle(new AccountRecoveryRequest($admin, 'Forgotten password'), 'short'))
        ->toThrow(ValidationException::class);

    expect(Hash::check('password-for-tests', $admin->refresh()->password))->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::PasswordReset->value)->exists())->toBeFalse();
});
