<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use SensitiveParameter;

/**
 * Creates a platform admin (console only: `paylink:create-platform-admin` and
 * the local DevelopmentSeeder). 2FA is set up on first sign-in, where it is
 * mandatory.
 */
final readonly class CreatePlatformAdmin
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(string $name, string $email, #[SensitiveParameter] string $password, PlatformRole $role): PlatformAdmin
    {
        $admin = new PlatformAdmin([
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ]);
        $admin->forceFill(['role' => $role])->save();

        $this->audit->record(AuditAction::PlatformAdminCreated, $admin, ['role' => $role->value], platform: true);

        return $admin;
    }
}
