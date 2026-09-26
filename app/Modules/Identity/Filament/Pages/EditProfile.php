<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Pages;

use App\Modules\Identity\Services\ImpersonationState;
use App\Support\Filament\Forms\PasswordField;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Profile page of both panels: name, password and 2FA (Filament's TOTP
 * management, audited through AuditedAppAuthentication). E-mail changes are
 * not offered in the MVP. Not available while impersonating: the session is
 * read-only and the admin must never change the user's credentials or
 * factors (plan 17.4). The three password fields are PasswordField
 * (ADR-0046): the new one validates against PasswordPolicy with its live
 * checklist; Filament's hashing, `same` and current-password rules are kept.
 */
final class EditProfile extends BaseEditProfile
{
    public function mount(): void
    {
        abort_if(app(ImpersonationState::class)->isActive(), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return PasswordField::make('password')
            ->forNewPassword()
            ->label(__('filament-panels::auth/pages/edit-profile.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password.validation_attribute'))
            ->dehydrated(static fn (#[SensitiveParameter] $state): bool => filled($state))
            ->dehydrateStateUsing(static fn (#[SensitiveParameter] string $state): string => Hash::make($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return PasswordField::make('passwordConfirmation')
            ->confirms('password')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password_confirmation.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password_confirmation.validation_attribute'))
            ->required()
            ->visible(static fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }

    /**
     * Without an e-mail field, the current password is only needed to change
     * the password.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return PasswordField::make('currentPassword')
            ->forCurrentPassword()
            ->label(__('filament-panels::auth/pages/edit-profile.form.current_password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.current_password.validation_attribute'))
            ->belowContent(__('filament-panels::auth/pages/edit-profile.form.current_password.below_content'))
            ->currentPassword(guard: Filament::getAuthGuard())
            ->required()
            ->visible(static fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }
}
