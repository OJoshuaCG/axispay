<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Http\Middleware\SetLocale;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Auth\AuditedAppAuthentication;
use App\Modules\Identity\Filament\Pages\EditProfile;
use App\Modules\Identity\Filament\Pages\Login;
use App\Modules\Shared\Support\Brand;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Livewire\SimpleUserMenu;
use Filament\Pages\SimplePage;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\LivewireManager;

/**
 * Configuration shared by the `admin` and `app` panels (ADR-0025, ADR-0030):
 * design-token palettes, self-hosted Mukta and Geist Mono, the token-based
 * theme, the dark-mode bridge, the language switcher and theme control, the
 * platform brand (ADR-0053), audited TOTP 2FA and the profile page. Each panel provider
 * adds its host, guard, resources and rules.
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
            ->font('Mukta', provider: ViteFontProvider::class)
            ->viteTheme('resources/css/filament/theme.css')
            // Light unless the person picks otherwise; the choice is saved in
            // localStorage['theme'], which Filament reads first (ADR-0044).
            ->darkMode()
            ->defaultThemeMode(ThemeMode::Light)
            ->brandName(static fn (): string => Brand::displayName())
            // The platform brand (topbar, mobile sidebar, sign-in, 2FA and
            // invitation pages) as the superadmin set it (ADR-0053): logo,
            // name, or both; the name alone when there is no logo. The dark
            // variant only when one exists (else the light logo serves both).
            // brandName() stays: page titles, alt text, the 2FA issuer.
            ->brandLogo(static fn (): Htmlable => self::brandMark(LogoVariant::Light))
            ->darkModeBrandLogo(static fn (): ?Htmlable => app(PlatformBrand::class)->showsLogo() && app(PlatformBrand::class)->hasVariant(LogoVariant::Dark)
                ? self::brandMark(LogoVariant::Dark)
                : null)
            ->brandLogoHeight('1.75rem')
            ->multiFactorAuthentication(
                [AuditedAppAuthentication::make()->recoverable()->brandName(Brand::displayName())],
                isRequired: $requireTwoFactor,
            )
            // Not persistent on purpose: Livewire's update route already runs
            // the `web` group (cookies, session, CSRF, SetLocale), and Filament
            // itself makes AuthenticateSession and its panel middleware
            // persistent. Re-running EncryptCookies/StartSession on the fake
            // request that Livewire builds for persistent middleware decrypts
            // the already-decrypted session cookie, fails, and swaps the store
            // to a new, never-saved session id: the next request then fails
            // the CSRF check with a 419.
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
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, static fn (): View => view('filament.partials.theme-bridge'))
            // 419 auto-reload and session keep-alive (ADR-0040).
            ->renderHook(PanelsRenderHook::HEAD_END, static fn (): View => view('filament.partials.session-resilience'))
            // One control bar per page. Topbar: panel-controls. Simple
            // pages: panel-controls inside Filament's signed-in header
            // (SimpleUserMenu), or guest-controls when there is no header.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, static fn (): View => view('filament.partials.panel-controls', [
                'simple' => app(LivewireManager::class)->current() instanceof SimpleUserMenu,
            ]))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, static fn (): View => view('filament.partials.guest-controls', [
                'show' => ! self::simplePageHasUserHeader(),
            ]));
    }

    /** The brand partial for one logo variant (ADR-0053). */
    private static function brandMark(LogoVariant $variant): Htmlable
    {
        return new HtmlString(view('filament.partials.brand', ['variant' => $variant])->render());
    }

    /** Display format of dates with time in every panel table and schema (ADR-0049). */
    public const string DATE_TIME_FORMAT = 'j M Y, H:i';

    /**
     * Defaults of Filament components in both panels, applied once at boot:
     *
     *  - action forms skip the browser's native validation bubbles (always in
     *    the browser's language); the server validates and answers in the
     *    viewer's language (ADR-0049);
     *  - dates with time read `26 sep 2026, 14:30` (month in the viewer's
     *    language, time zone set per panel).
     */
    public static function configureComponents(): void
    {
        Action::configureUsing(static fn (Action $action): Action => $action->extraModalWindowAttributes(['novalidate' => true], merge: true));
        Table::configureUsing(static fn (Table $table): Table => $table->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT));
        Schema::configureUsing(static fn (Schema $schema): Schema => $schema->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT));
    }

    /**
     * Mirrors the condition of Filament's simple layout
     * (filament-panels::components.layout.simple) for rendering the header
     * with the user menu. SIMPLE_LAYOUT_START renders inside the page's own
     * Livewire render, so the current component is the page.
     */
    private static function simplePageHasUserHeader(): bool
    {
        $page = app(LivewireManager::class)->current();

        return filament()->auth()->check()
            && filament()->hasUserMenu()
            && (! $page instanceof SimplePage || $page->hasTopbar());
    }
}
