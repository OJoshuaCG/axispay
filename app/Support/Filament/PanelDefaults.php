<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Http\Middleware\SetLocale;
use App\Modules\Identity\Auth\AuditedAppAuthentication;
use App\Modules\Identity\Filament\Pages\EditProfile;
use App\Modules\Identity\Filament\Pages\Login;
use App\Modules\Shared\Support\Brand;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Configuration shared by the `admin` and `app` panels (ADR-0025, ADR-0030):
 * design-token palettes, self-hosted Jost, the token-based theme, the
 * dark-mode bridge, the language switcher, audited TOTP 2FA and the profile
 * page. Each panel provider adds its host, guard, resources and rules.
 */
final class PanelDefaults
{
    public static function apply(Panel $panel, bool $requireTwoFactor): Panel
    {
        return $panel
            ->path('')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->colors(DesignTokenPalette::filamentColors())
            ->font('Jost', provider: ViteFontProvider::class)
            ->viteTheme('resources/css/filament/theme.css')
            ->darkMode()
            ->brandName(static fn (): string => Brand::displayName())
            ->multiFactorAuthentication(
                [AuditedAppAuthentication::make()->recoverable()->brandName(Brand::displayName())],
                isRequired: $requireTwoFactor,
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // After the session and cookies, like the `web` group (i18n.md).
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ], isPersistent: true)
            ->renderHook(PanelsRenderHook::HEAD_END, static fn (): View => view('filament.partials.theme-bridge'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, static fn (): View => view('filament.partials.panel-controls'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, static fn (): View => view('filament.partials.guest-controls'));
    }
}
