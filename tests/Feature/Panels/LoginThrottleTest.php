<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Filament\Pages\Login;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\travel;

/**
 * M6: per-account throttling on top of Filament's per-IP limit (5/min).
 *
 * @return Testable<Login>
 */
function attemptLogin(string $email, string $password): Testable
{
    return Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
}

it('throttles failed sign-ins per account and audits it without the e-mail', function (string $panel): void {
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $account = $panel === 'app' ? tenantUser(twoFactor: false) : platformAdmin(twoFactor: false);

    foreach (range(1, Login::MAX_ATTEMPTS_PER_ACCOUNT) as $attempt) {
        attemptLogin(strtoupper($account->email), 'wrong-password')->assertHasFormErrors(['email']);
    }

    // Past Filament's per-IP minute window: only the per-account limit remains.
    travel(2)->minutes();

    attemptLogin($account->email, 'password-for-tests')->assertHasFormErrors(['email']);
    expect(Filament::auth()->check())->toBeFalse();

    $entry = AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::LoginThrottled->value)->sole();
    expect($entry->changes['login_hash'] ?? null)->toBe(hash('sha256', mb_strtolower($account->email)))
        ->and((string) json_encode($entry->changes))->not->toContain($account->email);

    // After the decay window the account can sign in again.
    travel(16)->minutes();
    attemptLogin($account->email, 'password-for-tests')->assertHasNoFormErrors();
})->with(['app', 'admin']);

it('does not throttle other accounts', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $victim = tenantUser(twoFactor: false);
    $other = tenantUser(roles: [SystemRole::Viewer], twoFactor: false);

    foreach (range(1, Login::MAX_ATTEMPTS_PER_ACCOUNT) as $attempt) {
        attemptLogin($victim->email, 'wrong-password');
    }

    travel(2)->minutes();

    attemptLogin($other->email, 'password-for-tests')->assertHasNoFormErrors();
    expect(PlatformAdmin::query()->count())->toBe(0);
});
