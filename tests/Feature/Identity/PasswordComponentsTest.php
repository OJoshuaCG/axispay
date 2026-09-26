<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Pages\EditProfile;
use App\Modules\Identity\Support\PasswordPolicy;
use App\Modules\PlatformAdmin\Actions\CreatePlatformAdmin;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Support\Filament\Forms\PasswordField;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * ADR-0046: one password policy (PasswordPolicy) used by every place that
 * sets a password, and one password component per stack (<x-password-input>
 * on Blade pages, PasswordField in Filament) with the same modes.
 */

it('builds the rule and the checklist from the same config', function (): void {
    config(['axispay.passwords.min_length' => 12, 'axispay.passwords.check_uncompromised' => true]);

    expect(PasswordPolicy::rule())->toBeInstanceOf(Password::class)
        ->and(array_column(PasswordPolicy::requirements(), 'key'))->toBe(['min_length', 'uncompromised'])
        ->and(PasswordPolicy::requirements()[0]['min'])->toBe(12)
        ->and(PasswordPolicy::requirements()[1]['min'])->toBeNull();

    config(['axispay.passwords.check_uncompromised' => false]);

    expect(array_column(PasswordPolicy::requirements(), 'key'))->toBe(['min_length'])
        ->and(fn () => PasswordPolicy::validate(str_repeat('a', 11)))->toThrow(ValidationException::class);

    PasswordPolicy::validate(str_repeat('a', 12));
});

it('is what Password::defaults() returns, so nothing can fall back to another policy', function (): void {
    expect(Password::defaults())->toEqual(PasswordPolicy::rule());
});

it('is enforced by every action that sets a password', function (): void {
    expect(fn () => app(CreatePlatformAdmin::class)->handle('Short', 'short@axispay.test', 'short', PlatformRole::SupportReadonly))
        ->toThrow(ValidationException::class)
        ->and(PlatformAdmin::query()->where('email', 'short@axispay.test')->exists())->toBeFalse()
        ->and(fn () => ResetPassword::validate('short'))->toThrow(ValidationException::class);

    $admin = app(CreatePlatformAdmin::class)->handle('Long', 'long@axispay.test', 'a-long-enough-passphrase', PlatformRole::SupportReadonly);

    expect($admin->exists)->toBeTrue();
});

it('renders <x-password-input> in current mode: reveal button, no checklist', function (): void {
    $html = Blade::render('<x-password-input name="password" label="Password" required />');

    expect($html)->toContain('type="password"')
        ->toContain('autocomplete="current-password"')
        ->toContain('data-password-toggle')
        ->toContain('aria-controls="password"')
        ->toContain('aria-pressed="false"')
        ->toContain('aria-label="'.__('identity.password.show').'"')
        ->toContain('data-label-hide="'.__('identity.password.hide').'"')
        ->and(str_contains($html, 'data-password-requirements'))->toBeFalse()
        ->and(str_contains($html, 'password_confirmation'))->toBeFalse();
});

it('renders <x-password-input> in new mode with the checklist, the confirmation and its mismatch hint', function (): void {
    config(['axispay.passwords.check_uncompromised' => true]);

    $html = Blade::render('<x-password-input name="password" label="Password" mode="new" :confirm="true" confirm-label="Confirm" required />');

    expect($html)->toContain('autocomplete="new-password"')
        ->toContain('passwordrules="minlength: 12;"')
        ->toContain('data-password-requirements')
        ->toContain('data-min="12"')
        ->toContain(__('identity.password.requirements.min_length', ['count' => 12]))
        ->toContain(__('identity.password.requirements.uncompromised'))
        ->toContain('name="password_confirmation"')
        ->toContain('data-password-confirm-for="password"')
        ->toContain('aria-controls="password_confirmation"')
        ->toContain('data-password-mismatch')
        ->toContain('aria-describedby="password-requirements"')
        ->and(substr_count($html, 'data-password-toggle'))->toBe(2);
});

it('uses PasswordField on the Filament sign-in, re-authentication and profile forms', function (): void {
    get(appUrl('/login'))
        ->assertOk()
        ->assertSee('data-password-toggle', false)
        ->assertSee('autocomplete="current-password"', false)
        ->assertSee('x-bind:aria-pressed', false);

    expect(Reauthentication::field())->toBeInstanceOf(PasswordField::class)
        ->and(Reauthentication::field()->getPasswordMode())->toBe(PasswordField::MODE_CURRENT);
});

it('validates the profile password change against PasswordPolicy', function (): void {
    startSession();
    actingAsTenantUser(tenantUser(roles: [SystemRole::Viewer], twoFactor: false));

    Livewire::test(EditProfile::class)
        ->fillForm(['password' => 'short', 'passwordConfirmation' => 'short', 'currentPassword' => 'password-for-tests'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::test(EditProfile::class)
        ->fillForm(['password' => 'a-long-new-passphrase', 'passwordConfirmation' => 'something-else', 'currentPassword' => 'password-for-tests'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::test(EditProfile::class)
        ->fillForm(['password' => 'a-long-new-passphrase', 'passwordConfirmation' => 'a-long-new-passphrase', 'currentPassword' => 'password-for-tests'])
        ->call('save')
        ->assertHasNoFormErrors();
});
