<?php

declare(strict_types=1);

use App\Http\Controllers\LocaleController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\PlatformAdmin\Http\Controllers\ImpersonationController;
use App\Modules\PlatformAdmin\Http\Middleware\EnforceImpersonationWindow;
use App\Modules\Tenancy\Http\Controllers\LivemodeController;
use App\Modules\Tenancy\Http\Middleware\ResolveTenantContext;
use Illuminate\Support\Facades\Route;

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
    });
});

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
