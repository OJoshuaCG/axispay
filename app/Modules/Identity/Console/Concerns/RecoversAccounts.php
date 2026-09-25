<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console\Concerns;

use App\Modules\Identity\Actions\ResetTwoFactor;
use App\Modules\Identity\Data\AccountRecoveryRequest;
use App\Modules\Identity\Data\AccountRecoveryResult;
use App\Modules\Identity\Enums\AccountType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RecoverableAccounts;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Laravel\Prompts\text;

/**
 * Shared console plumbing of the account recovery commands (ADR-0041):
 * finding exactly one account, describing it and collecting the reason.
 * Business rules stay in the Actions.
 *
 * @mixin Command
 */
trait RecoversAccounts
{
    /**
     * Whether prompts can be answered: not `--no-interaction` and a real
     * terminal (same rule as Laravel's prompt set-up, which treats the test
     * runner as interactive). `docker exec` without `-t` has no terminal.
     */
    private function canPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return app()->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN));
    }

    /**
     * Resolves the account from the `email` argument and the `--type` option.
     * Prints the error and returns null when there is not exactly one match.
     */
    private function resolveAccount(RecoverableAccounts $accounts, string $purpose): User|PlatformAdmin|null
    {
        $email = trim((string) $this->argument('email'));
        $typeOption = $this->option('type');
        $type = null;

        if ($typeOption !== null) {
            $type = is_string($typeOption) ? AccountType::tryFrom($typeOption) : null;

            if ($type === null) {
                $this->error('--type must be "platform" or "tenant".');

                return null;
            }
        }

        $matches = array_values(array_filter(
            $accounts->findByEmail($email, $purpose),
            static fn (User|PlatformAdmin $account): bool => $type === null || AccountType::of($account) === $type,
        ));

        if ($matches === []) {
            $this->error($type === null
                ? 'No platform admin or tenant user with that e-mail.'
                : "No {$type->label()} with that e-mail.");

            return null;
        }

        if (count($matches) > 1) {
            $this->error('That e-mail belongs to a platform admin and to a tenant user. Pass --type=platform or --type=tenant.');

            return null;
        }

        return $matches[0];
    }

    private function describeAccount(User|PlatformAdmin $account): void
    {
        $rows = [
            ['Type', AccountType::of($account)->label()],
            ['Account ID', $account->id],
            ['Name', $account->name],
        ];

        if ($account instanceof User) {
            $rows[] = ['Tenant', $account->tenant !== null ? $account->tenant->display_name.' ('.$account->tenant_id.')' : $account->tenant_id];
        } else {
            $rows[] = ['Platform role', $account->role->value];
        }

        $rows[] = ['2FA enabled', ResetTwoFactor::hasTwoFactorData($account) ? 'yes' : 'no'];
        $rows[] = ['Disabled', $account->disabled_at !== null ? 'yes (since '.$account->disabled_at->toIso8601String().')' : 'no'];

        $this->table(['Field', 'Value'], $rows);
    }

    /**
     * The reason from `--reason` or a prompt. Prints the error and returns
     * null when it is too short or too long.
     */
    private function recoveryRequest(User|PlatformAdmin $account): ?AccountRecoveryRequest
    {
        $reason = $this->option('reason');

        if (! is_string($reason) || trim($reason) === '') {
            $reason = text(
                label: 'Reason (stored in the audit log; ticket number, how the identity was verified; no secrets)',
                required: true,
                hint: sprintf('%d to %d characters.', AccountRecoveryRequest::MIN_REASON_LENGTH, AccountRecoveryRequest::MAX_REASON_LENGTH),
            );
        }

        try {
            return new AccountRecoveryRequest($account, $reason);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    private function warnAboutPlatformAdmin(User|PlatformAdmin $account): void
    {
        if (! $account instanceof PlatformAdmin) {
            return;
        }

        $this->warn('This is a PLATFORM ADMIN account'.($account->isSuperadmin() ? ' (superadmin)' : '').'. It can see and act on every tenant.');
        $this->warn('Verify the requester\'s identity out of band (a known phone number or in person) before continuing.');
    }

    private function reportSessions(AccountRecoveryResult $result): void
    {
        if ($result->sessionsRevoked === null) {
            $this->warn('The session driver is not "database": open sessions could not be listed and were not revoked.');

            return;
        }

        $this->line("Sessions revoked: {$result->sessionsRevoked}. \"Remember me\" tokens rotated.");
    }
}
