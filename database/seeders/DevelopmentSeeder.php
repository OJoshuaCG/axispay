<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * LOCAL ONLY demo data: one superadmin and one active demo tenant with an
 * owner. Refuses to run outside APP_ENV=local. The credentials are printed
 * and documented in docs/development.md; they are not secrets and must never
 * exist anywhere but a developer machine. Both accounts must set up 2FA on
 * first sign-in (mandatory for superadmins and owners).
 */
final class DevelopmentSeeder extends Seeder
{
    public const string ADMIN_EMAIL = 'superadmin@paylink.test';

    public const string OWNER_EMAIL = 'owner@demo.paylink.test';

    public const string PASSWORD = 'local-dev-password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevelopmentSeeder only runs with APP_ENV=local.');
        }

        $this->call(PermissionCatalogSeeder::class);

        $admin = PlatformAdmin::query()->firstOrNew(['email' => self::ADMIN_EMAIL]);
        $admin->forceFill([
            'name' => 'Local Superadmin',
            'password' => self::PASSWORD,
            'role' => PlatformRole::Superadmin,
        ])->save();

        $tenant = Tenant::query()->firstOrNew(['display_name' => 'Demo Company']);
        $tenant->forceFill([
            'legal_name' => 'Demo Company S.A. de C.V.',
            'status' => TenantStatus::Active,
            'status_changed_at' => now(),
        ])->save();

        app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($tenant): void {
            $owner = User::query()->firstOrNew(['email' => self::OWNER_EMAIL]);
            $owner->forceFill([
                'tenant_id' => $tenant->id,
                'name' => 'Demo Owner',
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ])->save();

            $owner->syncRoles([SystemRole::Owner->value]);
        });

        $this->command->warn('Local development accounts (never use outside your machine):');
        $this->command->table(['Panel', 'URL host', 'E-mail', 'Password'], [
            ['admin', config()->string('paylink.surfaces.admin'), self::ADMIN_EMAIL, self::PASSWORD],
            ['app', config()->string('paylink.surfaces.app'), self::OWNER_EMAIL, self::PASSWORD],
        ]);
        $this->command->line('Both accounts must set up 2FA (TOTP) on first sign-in.');
    }
}
