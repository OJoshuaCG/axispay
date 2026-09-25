<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\ResetTwoFactor;
use App\Modules\Identity\Console\Concerns\RecoversAccounts;
use App\Modules\Identity\Enums\AccountType;
use App\Modules\Identity\Services\RecoverableAccounts;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * Production recovery of a lost authenticator (ADR-0041). Works in every
 * environment. A reason is mandatory and audited; `--force` skips the
 * confirmation only together with `--reason`, for non-interactive use.
 */
final class ResetTwoFactorCommand extends Command
{
    use RecoversAccounts;

    protected $signature = 'axispay:reset-2fa
        {email : E-mail of the platform admin or tenant user}
        {--type= : platform|tenant (required when the e-mail exists in both)}
        {--reason= : Why (ticket, how the identity was verified); stored in the audit log}
        {--force : Skip the confirmation (only together with --reason)}';

    protected $description = 'Reset the 2FA of a platform admin or tenant user (audited; signs the account out everywhere)';

    public function handle(RecoverableAccounts $accounts, ResetTwoFactor $reset): int
    {
        $force = (bool) $this->option('force');
        $hasReason = is_string($this->option('reason')) && trim($this->option('reason')) !== '';

        if ($force && ! $hasReason) {
            $this->error('--force requires --reason.');

            return self::FAILURE;
        }

        if (! $this->canPrompt() && ! ($force && $hasReason)) {
            $this->error('Without an interactive terminal (--no-interaction, or docker exec without -it), pass both --reason and --force.');

            return self::FAILURE;
        }

        $account = $this->resolveAccount($accounts, 'account recovery lookup (axispay:reset-2fa)');

        if ($account === null) {
            return self::FAILURE;
        }

        $this->describeAccount($account);

        if (! ResetTwoFactor::hasTwoFactorData($account)) {
            $this->info('This account has no 2FA set up. Nothing to do.');

            return self::SUCCESS;
        }

        $request = $this->recoveryRequest($account);

        if ($request === null) {
            return self::FAILURE;
        }

        $this->line('This removes the 2FA secret and the recovery codes, signs the account out of every session and clears its sign-in throttle.');
        $this->warnAboutPlatformAdmin($account);

        if (! $force && ! confirm('Reset the 2FA of this account?', default: false)) {
            $this->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        $result = $reset->handle($request);

        if (! $result->changed) {
            $this->info('This account has no 2FA set up. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info('2FA reset for '.AccountType::of($account)->label()." {$account->id}.");
        $this->reportSessions($result);
        $this->line($account instanceof PlatformAdmin
            ? 'The next sign-in requires setting 2FA up again.'
            : 'The next sign-in requires setting 2FA up again if the user holds a sensitive permission; otherwise it can be re-enabled from the profile page.');

        return self::SUCCESS;
    }
}
