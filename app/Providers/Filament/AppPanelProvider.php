<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Modules\Access\Filament\Resources\Roles\RoleResource;
use App\Modules\ApiKeys\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Modules\Audit\Filament\Resources\AuditLogs\AuditLogResource;
use App\Modules\Branding\Filament\Pages\TenantBrandingSettings;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Identity\Filament\Resources\Users\UserResource;
use App\Modules\Identity\Http\Middleware\RequireTwoFactorForSensitiveUsers;
use App\Modules\Legal\Filament\Pages\TenantLegalSettings;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\PlatformAdmin\Http\Middleware\EnforceImpersonationWindow;
use App\Modules\Tenancy\Http\Middleware\ApplyTenantTimezone;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantContext;
use App\Support\Filament\PanelDefaults;
use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;

/**
 * Tenant panel on the app host (plan 4.1): `web` guard, tenant derived from
 * the user, test/live selector in the session (plan 6.3). 2FA is required for
 * users with sensitive permissions (plan 17.3).
 */
final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $host = config('axispay.surfaces.app');

        return PanelDefaults::apply($panel, requireTwoFactor: true)
            ->id('app')
            ->default()
            ->domain(is_string($host) ? $host : 'app.localhost')
            ->authGuard('web')
            // Only users holding a sensitive permission are forced to set up 2FA.
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireTwoFactorForSensitiveUsers::class)
            ->resources([
                PaymentLinkResource::class,
                UserResource::class,
                RoleResource::class,
                AuditLogResource::class,
                ApiKeyResource::class,
            ])
            ->pages([Dashboard::class, StripeConnection::class, TenantBrandingSettings::class, TenantLegalSettings::class])
            ->widgets([AccountWidget::class])
            ->authMiddleware([
                Authenticate::class,
                ResolveTenantContext::class,
                ApplyTenantTimezone::class,
                EnforceImpersonationWindow::class,
            ], isPersistent: true)
            ->renderHook(PanelsRenderHook::BODY_START, static fn (): View => view('filament.app.banners'));
    }
}
