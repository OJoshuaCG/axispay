<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\PlatformAdmin\Filament\Resources\AuditLogs\AuditLogResource;
use App\Modules\PlatformAdmin\Filament\Resources\PlatformAdmins\PlatformAdminResource;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Support\Filament\PanelDefaults;
use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;

/**
 * Platform panel on the admin host (plan 4.1, 17.4): `platform` guard,
 * mandatory 2FA for every platform admin.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $host = config('axispay.surfaces.admin');

        return PanelDefaults::apply($panel, requireTwoFactor: true)
            ->id('admin')
            ->domain(is_string($host) ? $host : 'admin.localhost')
            ->authGuard('platform')
            ->resources([
                TenantResource::class,
                PlatformAdminResource::class,
                AuditLogResource::class,
            ])
            ->pages([Dashboard::class])
            ->widgets([AccountWidget::class])
            ->authMiddleware([Authenticate::class], isPersistent: true);
    }
}
