<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\ResetTwoFactor;
use App\Modules\Identity\Data\AccountRecoveryRequest;
use App\Modules\Identity\Services\RecoverableAccounts;
use Illuminate\Console\Command;

/**
 * LOCAL ONLY: resets the 2FA of every tenant user and platform admin with the
 * given e-mail, without prompts, so the next sign-in asks to set 2FA up
 * again. Refuses to run outside APP_ENV=local; in any other environment use
 * `axispay:reset-2fa` (ADR-0041). Same Action, so it is audited too.
 */
final class DevResetTwoFactorCommand extends Command
{
    protected $signature = 'axispay:dev-reset-2fa {email : E-mail of the tenant user or platform admin}';

    protected $description = 'Local only: reset 2FA of a user or platform admin';

    public function handle(RecoverableAccounts $accounts, ResetTwoFactor $reset): int
    {
        if (! app()->environment('local')) {
            $this->error('axispay:dev-reset-2fa only runs with APP_ENV=local. Use axispay:reset-2fa instead.');

            return self::FAILURE;
        }

        $found = $accounts->findByEmail((string) $this->argument('email'), 'local 2FA reset (axispay:dev-reset-2fa)');

        if ($found === []) {
            $this->error('No user or platform admin with that e-mail.');

            return self::FAILURE;
        }

        foreach ($found as $account) {
            $reset->handle(new AccountRecoveryRequest($account, 'local development reset', source: 'dev-reset-2fa'));
        }

        $this->info('2FA reset. The next sign-in will ask to set it up again.');

        return self::SUCCESS;
    }
}
