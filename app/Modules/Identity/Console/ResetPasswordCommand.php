<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Console\Concerns\RecoversAccounts;
use App\Modules\Identity\Enums\AccountType;
use App\Modules\Identity\Services\RecoverableAccounts;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;

/**
 * Production recovery of a forgotten password (ADR-0041). The new password is
 * only accepted from a hidden prompt, entered twice: never as an argument or
 * option (it would end up in the shell history and the process list), so the
 * command refuses to run without an interactive terminal.
 */
final class ResetPasswordCommand extends Command
{
    use RecoversAccounts;

    protected $signature = 'axispay:reset-password
        {email : E-mail of the platform admin or tenant user}
        {--type= : platform|tenant (required when the e-mail exists in both)}
        {--reason= : Why (ticket, how the identity was verified); stored in the audit log}';

    protected $description = 'Set a new password for a platform admin or tenant user (interactive, audited; signs the account out everywhere)';

    public function handle(RecoverableAccounts $accounts, ResetPassword $reset): int
    {
        if (! $this->canPrompt()) {
            $this->error('axispay:reset-password needs an interactive terminal (docker exec -it, no --no-interaction): the password is only read from a hidden prompt.');

            return self::FAILURE;
        }

        $account = $this->resolveAccount($accounts, 'account recovery lookup (axispay:reset-password)');

        if ($account === null) {
            return self::FAILURE;
        }

        $this->describeAccount($account);

        $request = $this->recoveryRequest($account);

        if ($request === null) {
            return self::FAILURE;
        }

        $secret = password('New password', required: true);

        if (! hash_equals($secret, password('Repeat the new password', required: true))) {
            $this->error('The passwords do not match. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            ResetPassword::validate($secret);
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->line('This sets the new password, signs the account out of every session and clears its sign-in throttle. 2FA is not changed.');
        $this->warnAboutPlatformAdmin($account);

        if (! confirm('Set the new password for this account?', default: false)) {
            $this->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        $result = $reset->handle($request, $secret);

        $this->info('Password reset for '.AccountType::of($account)->label()." {$account->id}.");
        $this->reportSessions($result);
        $this->line('Share the new password over a separate secure channel and ask the owner to change it after signing in.');

        return self::SUCCESS;
    }
}
