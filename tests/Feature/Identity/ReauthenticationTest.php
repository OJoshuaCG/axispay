<?php

declare(strict_types=1);

use App\Modules\Access\Actions\ChangeUserRoles;
use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\Reauthenticate;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\Identity\Services\ReauthenticationWindow;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\startSession;
use function Pest\Laravel\travel;

beforeEach(function (): void {
    startSession();
});

it('blocks sensitive actions outside the re-authentication window', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    app(ChangeUserRoles::class)->handle($owner, $target, [SystemRole::Finance]);
})->throws(ReauthenticationRequiredException::class);

it('opens a 10-minute window after confirming the password', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    $target = tenantUser($owner->tenant, [SystemRole::Viewer]);

    app(Reauthenticate::class)->handle($owner, 'password-for-tests');
    app(ChangeUserRoles::class)->handle($owner, $target, [SystemRole::Finance]);

    expect($target->refresh()->hasRole(SystemRole::Finance->value))->toBeTrue();

    travel(11)->minutes();

    expect(app(ReauthenticationWindow::class)->isConfirmed())->toBeFalse();
    expect(AuditLog::query()->where('action', AuditAction::ReauthenticationConfirmed->value)->count())->toBe(1);
});

it('accepts a current 2FA code instead of the password', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    $code = app(AppAuthentication::class)->getCurrentCode($owner, 'JBSWY3DPEHPK3PXP');

    app(Reauthenticate::class)->handle($owner, $code);

    expect(app(ReauthenticationWindow::class)->isConfirmed())->toBeTrue();
});

it('audits and throttles failed confirmations', function (): void {
    $owner = actingAsTenantUser(tenantUser());

    foreach (range(1, 5) as $attempt) {
        expect(fn () => app(Reauthenticate::class)->handle($owner, 'wrong'))->toThrow(ValidationException::class);
    }

    expect(fn () => app(Reauthenticate::class)->handle($owner, 'password-for-tests'))->toThrow(ValidationException::class)
        ->and(AuditLog::query()->where('action', AuditAction::ReauthenticationFailed->value)->count())->toBe(5)
        ->and(app(ReauthenticationWindow::class)->isConfirmed())->toBeFalse();
});

it('can never be satisfied while impersonating (plan 17.4)', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    app(ReauthenticationWindow::class)->confirm();
    app(ImpersonationState::class)->start('01J8Z3Q6T4Y0V8KX2M1N5P7R9A', '01J8Z3Q6T4Y0V8KX2M1N5P7R9B');

    expect(app(ReauthenticationWindow::class)->isConfirmed())->toBeFalse()
        ->and(fn () => app(Reauthenticate::class)->handle($owner, 'password-for-tests'))->toThrow(ReauthenticationRequiredException::class);
});
