<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Console;

use App\Modules\PlatformAdmin\Actions\CreatePlatformAdmin;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a platform admin interactively. The password is prompted, never
 * passed as an argument (it would end up in the shell history).
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'paylink:create-platform-admin';

    protected $description = 'Create a platform admin (2FA is set up on first sign-in)';

    public function handle(CreatePlatformAdmin $create): int
    {
        $name = text('Name', required: true);
        $email = text('E-mail', required: true);
        $role = select('Role', PlatformRole::options(), default: PlatformRole::SupportReadonly->value);
        $secret = password('Password (min. 12 characters)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'password' => $secret, 'role' => $role],
            [
                'email' => ['required', 'email', 'max:254', Rule::unique('platform_admins', 'email')],
                'password' => ['required', Password::defaults()],
                'role' => ['required', Rule::enum(PlatformRole::class)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = $create->handle($name, $email, $secret, PlatformRole::from(is_string($role) ? $role : ''));
        $this->info("Platform admin {$admin->id} created. 2FA set-up is required on first sign-in.");

        return self::SUCCESS;
    }
}
