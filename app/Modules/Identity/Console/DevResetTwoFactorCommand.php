<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * LOCAL ONLY: removes the 2FA secret and recovery codes of a tenant user or
 * platform admin, so the next sign-in asks to set 2FA up again. Refuses to run
 * outside APP_ENV=local. Audited.
 */
final class DevResetTwoFactorCommand extends Command
{
    protected $signature = 'paylink:dev-reset-2fa {email : E-mail of the tenant user or platform admin}';

    protected $description = 'Local only: reset 2FA of a user or platform admin';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        if (! app()->environment('local')) {
            $this->error('paylink:dev-reset-2fa only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $reset = 0;

        $admin = PlatformAdmin::query()->where('email', $email)->first();

        if ($admin !== null) {
            $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $audit->record(AuditAction::TwoFactorDisabled, $admin, ['source' => 'dev-reset-2fa'], platform: true, actor: Actor::system());
            $reset++;
        }

        $user = $context->runAsPlatform('local 2FA reset (paylink:dev-reset-2fa)', static fn (): ?User => User::query()->where('email', $email)->first());

        if ($user !== null) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $audit->record(AuditAction::TwoFactorDisabled, $user, ['source' => 'dev-reset-2fa'], tenantId: $user->tenant_id, actor: Actor::system());
            $reset++;
        }

        if ($reset === 0) {
            $this->error('No user or platform admin with that e-mail.');

            return self::FAILURE;
        }

        $this->info('2FA reset. The next sign-in will ask to set it up again.');

        return self::SUCCESS;
    }
}
