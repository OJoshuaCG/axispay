<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Filament\Pages\Login;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Pest\Laravel\artisan;

const RESET_REASON_PROMPT = 'Reason (stored in the audit log; ticket number, how the identity was verified; no secrets)';

beforeEach(function (): void {
    config(['session.driver' => 'database', 'session.table' => 'sessions']);
});

/**
 * @param  array<string, mixed>  $parameters
 */
function recoveryCommand(string $command, array $parameters): PendingCommand
{
    $pending = artisan($command, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new LogicException('Console output mocking must be enabled for these tests.');
    }

    return $pending;
}

function cliSessionFor(User|PlatformAdmin $account): void
{
    DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(20)),
        'user_id' => $account->id,
        'payload' => '',
        'last_activity' => time(),
    ]);
}

function cliAuditCount(AuditAction $action): int
{
    return AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', $action->value)->count();
}

function cliTenantUser(User $user): User
{
    return app(TenantContext::class)->runAsTenant($user->tenant_id, false, static fn (): User => User::query()->findOrFail($user->id));
}

// axispay:reset-2fa

it('resets the 2FA of a platform admin after a reason and a confirmation', function (): void {
    $admin = platformAdmin();
    cliSessionFor($admin);

    recoveryCommand('axispay:reset-2fa', ['email' => strtoupper($admin->email)])
        ->expectsOutputToContain('PLATFORM ADMIN')
        ->expectsQuestion(RESET_REASON_PROMPT, 'Lost phone, identity verified by call')
        ->expectsConfirmation('Reset the 2FA of this account?', 'yes')
        ->expectsOutputToContain('Sessions revoked: 1')
        ->assertExitCode(0);

    expect($admin->refresh()->two_factor_secret)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeFalse()
        ->and(cliAuditCount(AuditAction::TwoFactorReset))->toBe(1);
});

it('looks tenant users up through the audited platform context and leaves no context behind', function (): void {
    $user = tenantUser();

    recoveryCommand('axispay:reset-2fa', ['email' => $user->email, '--reason' => 'Lost phone, ticket OPS-42', '--force' => true])
        ->assertExitCode(0);

    $lookup = AuditLog::query()->withoutGlobalScope(TenantScope::class)
        ->where('action', AuditAction::PlatformContextEntered->value)->sole();

    expect(cliTenantUser($user)->two_factor_secret)->toBeNull()
        ->and($lookup->changes)->toBe(['reason' => 'account recovery lookup (axispay:reset-2fa)'])
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and(app(TenantContext::class)->isPlatformMode())->toBeFalse();
});

it('requires --type when the e-mail belongs to a platform admin and a tenant user', function (): void {
    $user = tenantUser();
    $admin = platformAdmin();
    $admin->forceFill(['email' => $user->email])->save();

    recoveryCommand('axispay:reset-2fa', ['email' => $user->email, '--reason' => 'Lost phone, ticket OPS-1', '--force' => true])
        ->expectsOutputToContain('Pass --type=platform or --type=tenant')
        ->assertExitCode(1);

    expect(cliAuditCount(AuditAction::TwoFactorReset))->toBe(0);

    recoveryCommand('axispay:reset-2fa', ['email' => $user->email, '--type' => 'tenant', '--reason' => 'Lost phone, ticket OPS-1', '--force' => true])
        ->assertExitCode(0);

    expect(cliTenantUser($user)->two_factor_secret)->toBeNull()
        ->and($admin->refresh()->two_factor_secret)->not->toBeNull();
});

it('aborts without changes when the confirmation is declined', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-2fa', ['email' => $admin->email, '--reason' => 'Lost phone, ticket OPS-2'])
        ->expectsConfirmation('Reset the 2FA of this account?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertExitCode(1);

    expect($admin->refresh()->two_factor_secret)->not->toBeNull();
});

it('refuses a reason that is too short', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-2fa', ['email' => $admin->email])
        ->expectsQuestion(RESET_REASON_PROMPT, 'lost')
        ->assertExitCode(1);

    expect($admin->refresh()->two_factor_secret)->not->toBeNull();
});

it('refuses --force without --reason', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-2fa', ['email' => $admin->email, '--force' => true])
        ->expectsOutputToContain('--force requires --reason')
        ->assertExitCode(1);

    expect($admin->refresh()->two_factor_secret)->not->toBeNull();
});

it('refuses non-interactive use without --reason and --force', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-2fa', ['email' => $admin->email, '--reason' => 'Lost phone, ticket OPS-3', '--no-interaction' => true])
        ->assertExitCode(1);

    expect($admin->refresh()->two_factor_secret)->not->toBeNull();
});

it('is a no-op for an account without 2FA', function (): void {
    $admin = platformAdmin(twoFactor: false);

    recoveryCommand('axispay:reset-2fa', ['email' => $admin->email, '--reason' => 'Lost phone, ticket OPS-4', '--force' => true])
        ->expectsOutputToContain('Nothing to do')
        ->assertExitCode(0);

    expect(cliAuditCount(AuditAction::TwoFactorReset))->toBe(0);
});

it('fails for an unknown e-mail', function (): void {
    recoveryCommand('axispay:reset-2fa', ['email' => 'nobody@example.com', '--reason' => 'Lost phone, ticket OPS-5', '--force' => true])
        ->assertExitCode(1);
});

it('runs in production', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-2fa', ['email' => $admin->email, '--reason' => 'Lost phone, ticket OPS-6', '--force' => true])
        ->assertExitCode(0);

    expect($admin->refresh()->two_factor_secret)->toBeNull();
});

it('keeps the dev reset local only', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:dev-reset-2fa', ['email' => $admin->email])->assertExitCode(1);

    expect($admin->refresh()->two_factor_secret)->not->toBeNull();
});

// axispay:reset-password

it('resets a password from the hidden prompt and clears the throttle', function (): void {
    $user = tenantUser();
    cliSessionFor($user);
    RateLimiter::hit(Login::throttleKeyFor('app', $user->email), Login::DECAY_SECONDS);

    recoveryCommand('axispay:reset-password', ['email' => $user->email, '--reason' => 'Forgotten password, ticket OPS-8'])
        ->expectsQuestion('New password', 'a-brand-new-passphrase')
        ->expectsQuestion('Repeat the new password', 'a-brand-new-passphrase')
        ->expectsConfirmation('Set the new password for this account?', 'yes')
        ->doesntExpectOutputToContain('a-brand-new-passphrase')
        ->assertExitCode(0);

    $entry = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::PasswordReset->value)->sole();

    expect(Hash::check('a-brand-new-passphrase', cliTenantUser($user)->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(RateLimiter::attempts(Login::throttleKeyFor('app', $user->email)))->toBe(0)
        ->and(json_encode($entry->changes))->not->toContain('a-brand-new-passphrase');
});

it('refuses mismatching passwords', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-password', ['email' => $admin->email, '--reason' => 'Forgotten password, ticket OPS-9'])
        ->expectsQuestion('New password', 'a-brand-new-passphrase')
        ->expectsQuestion('Repeat the new password', 'another-passphrase-x')
        ->expectsOutputToContain('do not match')
        ->assertExitCode(1);

    expect(Hash::check('password-for-tests', $admin->refresh()->password))->toBeTrue();
});

it('enforces the password policy', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-password', ['email' => $admin->email, '--reason' => 'Forgotten password, ticket OPS-10'])
        ->expectsQuestion('New password', 'short')
        ->expectsQuestion('Repeat the new password', 'short')
        ->assertExitCode(1);

    expect(Hash::check('password-for-tests', $admin->refresh()->password))->toBeTrue()
        ->and(cliAuditCount(AuditAction::PasswordReset))->toBe(0);
});

it('aborts the password reset when the confirmation is declined', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-password', ['email' => $admin->email, '--reason' => 'Forgotten password, ticket OPS-11'])
        ->expectsQuestion('New password', 'a-brand-new-passphrase')
        ->expectsQuestion('Repeat the new password', 'a-brand-new-passphrase')
        ->expectsConfirmation('Set the new password for this account?', 'no')
        ->assertExitCode(1);

    expect(Hash::check('password-for-tests', $admin->refresh()->password))->toBeTrue();
});

it('refuses to reset a password without an interactive terminal', function (): void {
    $admin = platformAdmin();

    recoveryCommand('axispay:reset-password', ['email' => $admin->email, '--reason' => 'Forgotten password, ticket OPS-12', '--no-interaction' => true])
        ->expectsOutputToContain('interactive terminal')
        ->assertExitCode(1);
});

it('has no option that accepts the password', function (): void {
    $command = Artisan::all()['axispay:reset-password'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new LogicException('axispay:reset-password is not registered.');
    }

    $definition = $command->getDefinition();

    expect(array_keys($definition->getOptions()))->not->toContain('password')
        ->and(array_keys($definition->getArguments()))->toBe(['email']);
});
