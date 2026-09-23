<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use SensitiveParameter;

/**
 * Filament's TOTP provider (secret encrypted, recovery codes hashed and
 * encrypted) with audit entries for enabling and disabling 2FA and for
 * regenerating recovery codes (plan 7.1). Used by both panels.
 */
class AuditedAppAuthentication extends AppAuthentication
{
    private bool $enabledInThisRequest = false;

    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        $this->enabledInThisRequest = $secret !== null;
        $this->record($user, $secret === null ? AuditAction::TwoFactorDisabled : AuditAction::TwoFactorEnabled);
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        parent::saveRecoveryCodes($user, $codes);

        // Codes saved right after enabling 2FA are part of the set-up, not a regeneration.
        if ($codes !== null && ! $this->enabledInThisRequest) {
            $this->record($user, AuditAction::TwoFactorRecoveryCodesRegenerated);
        }
    }

    private function record(object $user, AuditAction $action): void
    {
        $audit = app(AuditLogger::class);

        if ($user instanceof User) {
            $audit->record($action, $user, tenantId: $user->tenant_id);
        } elseif ($user instanceof PlatformAdmin) {
            $audit->record($action, $user, platform: true);
        }
    }
}
