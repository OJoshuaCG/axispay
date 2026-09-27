<?php

declare(strict_types=1);

use App\Modules\Checkout\Http\Controllers\CheckoutAttemptController;
use App\Modules\Checkout\Http\Controllers\CheckoutPageController;
use App\Modules\Checkout\Http\Controllers\CheckoutStatusController;
use App\Modules\Checkout\Http\Controllers\SandboxNextActionController;
use App\Modules\Checkout\Http\Middleware\CheckoutSecurityHeaders;
use App\Modules\Gateways\Sandbox\SandboxMode;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public checkout (plan 11.5), pay host only
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php with the `web` middleware group on the
| pay host: an anonymous session (own cookie, SameSite=Lax) gives the CSRF
| token of the POST endpoints (ADR-0051). No API keys. Every response gets
| the checkout's security headers (CSP, no framing, no Referer, no-store).
| The token is matched loosely here: the resolver answers the same 404 for
| any invalid token.
|
*/

Route::middleware([CheckoutSecurityHeaders::class])
    ->prefix('/l/{token}')
    ->where(['token' => '[^/]{1,128}'])
    ->name('checkout.')
    ->group(function (): void {
        Route::get('/', [CheckoutPageController::class, 'show'])->middleware('throttle:120,1')->name('show');
        Route::get('/complete', [CheckoutPageController::class, 'complete'])->middleware('throttle:120,1')->name('complete');
        Route::get('/status', CheckoutStatusController::class)->middleware('throttle:120,1')->name('status');
        Route::post('/attempts', [CheckoutAttemptController::class, 'store'])->middleware('throttle:30,1')->name('attempts.store');
        Route::post('/attempts/continue', [CheckoutAttemptController::class, 'continue'])->middleware('throttle:30,1')->name('attempts.continue');

        // ADR-0051: the sandbox bank of the Stripe.js stub (local/testing only).
        if (SandboxMode::enabled()) {
            Route::post('/sandbox/next-action', SandboxNextActionController::class)->middleware('throttle:30,1')->name('sandbox.next-action');
        }
    });
