<?php

declare(strict_types=1);

use App\Http\Controllers\LocaleController;
use App\Http\Middleware\SetLocale;
use App\Modules\Branding\Http\Controllers\PlatformLogoController;
use App\Modules\Gateways\Http\Controllers\OnboardingRefreshController;
use App\Modules\Gateways\Http\Controllers\OnboardingReturnController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\PlatformAdmin\Http\Controllers\ImpersonationController;
use App\Modules\PlatformAdmin\Http\Middleware\EnforceImpersonationWindow;
use App\Modules\Shared\Http\Controllers\SessionPingController;
use App\Modules\Tenancy\Http\Controllers\LivemodeController;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantContext;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Tenant panel host (ADR-027): routes outside Filament
|--------------------------------------------------------------------------
|
| The panels themselves are registered by their Filament panel providers on
| the admin and app hosts. These routes must be registered before the
| host-less routes below, so they win on the app host.
|
*/

$appHost = config('axispay.surfaces.app');

Route::domain(is_string($appHost) ? $appHost : 'app.localhost')->group(function (): void {
    // Invitations (plan 17.3): signed links, single-use tokens.
    Route::middleware(['signed', 'throttle:20,1'])->group(function (): void {
        Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
        // Impersonation hand-off from the admin host (plan 17.4).
        Route::get('/impersonation/{token}', [ImpersonationController::class, 'consume'])->name('impersonation.consume');
    });

    Route::middleware(['auth:web'])->group(function (): void {
        Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');

        // Test/live selector (plan 6.3).
        Route::post('/mode', LivemodeController::class)
            ->middleware([ResolveTenantContext::class, EnforceImpersonationWindow::class])
            ->name('app.livemode.update');

        // Stripe hosted onboarding comes back here (plan 12.3.1). The
        // connection is resolved through the tenant scope: 404 across tenants.
        Route::middleware([ResolveTenantContext::class, EnforceImpersonationWindow::class, 'throttle:30,1'])
            ->prefix('/gateways/stripe/onboarding/{connection}')
            ->where(['connection' => '[0-9A-Za-z]{26}'])
            ->group(function (): void {
                Route::get('/return', OnboardingReturnController::class)->name('gateways.stripe.onboarding.return');
                Route::get('/refresh', OnboardingRefreshController::class)->name('gateways.stripe.onboarding.refresh');
            });
    });
});

/*
|--------------------------------------------------------------------------
| Both panel hosts
|--------------------------------------------------------------------------
|
| Session keep-alive of the panels (ADR-0040): 204, never cached. Guests may
| ping too, so an open sign-in page keeps a valid CSRF token.
|
*/

foreach (['admin', 'app'] as $panel) {
    $panelHost = config("axispay.surfaces.{$panel}");

    Route::domain(is_string($panelHost) ? $panelHost : "{$panel}.localhost")
        ->middleware('throttle:30,1')
        ->get('/session/ping', SessionPingController::class)
        ->name("{$panel}.session.ping");
}

/*
|--------------------------------------------------------------------------
| Platform logo (ADR-0053): admin, app and pay hosts
|--------------------------------------------------------------------------
|
| Same-origin on every host that shows it (the checkout allows images from
| 'self' only). Versioned URL cached for a year, so it runs without the
| session, cookie and locale middleware: the response never sets a
| cookie and does not vary by viewer.
|
*/

foreach (['admin', 'app', 'pay'] as $surface) {
    $surfaceHost = config("axispay.surfaces.{$surface}");

    Route::domain(is_string($surfaceHost) ? $surfaceHost : "{$surface}.localhost")
        ->withoutMiddleware([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SetLocale::class,
        ])
        ->get('/branding/platform-logo/{variant}/{version}.png', PlatformLogoController::class)
        ->where(['variant' => 'light|dark', 'version' => '[0-9a-z]{26}'])
        ->name("{$surface}.branding.platform-logo");
}

/*
|--------------------------------------------------------------------------
| Any host
|--------------------------------------------------------------------------
*/

// Language switcher target (<x-language-switcher>). POST: it changes state.
Route::post('/locale', LocaleController::class)->name('locale.update');

Route::get('/', function () {
    return view('welcome');
});

// Design-system preview: local environment only.
if (app()->environment('local')) {
    Route::view('/design-system', 'design-system')->name('design-system');
}
