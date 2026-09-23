<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Pages;

use App\Modules\Identity\Services\ImpersonationState;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Profile page of both panels: name, password and 2FA (Filament's TOTP
 * management, audited through AuditedAppAuthentication). E-mail changes are
 * not offered in the MVP. Not available while impersonating: the session is
 * read-only and the admin must never change the user's credentials or
 * factors (plan 17.4).
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

    /**
     * Without an e-mail field, the current password is only needed to change
     * the password.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(static fn (Get $get): bool => filled($get('password')));
    }
}
