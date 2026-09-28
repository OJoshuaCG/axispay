<?php

declare(strict_types=1);

use App\Modules\Checkout\Http\CheckoutRateLimits;
use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Http\Controllers\CheckoutAttemptController;
use App\Modules\Checkout\Http\Controllers\CheckoutPageController;
use App\Modules\Checkout\Http\Controllers\CheckoutStatusController;
use App\Modules\Checkout\Http\Controllers\SandboxNextActionController;
use App\Modules\Checkout\Http\Middleware\ApplyCheckoutLocale;
use App\Modules\Gateways\Sandbox\SandboxMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Public checkout (plan 11.5), pay host only
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php with the checkout's security headers
| (outermost: CSP, no framing, no Referer, no-store, even for 404 and 419)
| and the `web` group on the pay host: an anonymous session (own cookie,
| SameSite=Lax) gives the CSRF token of the POST endpoints (ADR-0051). No
| API keys.
| The token is matched loosely here: the resolver answers the same 404 for
| any invalid token. Each group has its own request limit
| (CheckoutRateLimits), so polling never eats into the budget of paying.
|
*/

Route::prefix('/l/{token}')
    ->where(['token' => '[^/]{1,128}'])
    ->middleware(ApplyCheckoutLocale::class)
    ->name('checkout.')
    ->group(function (): void {
        Route::get('/', [CheckoutPageController::class, 'show'])->middleware('throttle:'.CheckoutRateLimits::PAGE)->name('show');
        Route::get('/complete', [CheckoutPageController::class, 'complete'])->middleware('throttle:'.CheckoutRateLimits::COMPLETE)->name('complete');
        // A read with no session: polling never starts or refreshes one (no cookie, no session row).
        Route::get('/status', CheckoutStatusController::class)
            ->middleware('throttle:'.CheckoutRateLimits::STATUS)
            ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
            ->name('status');
        Route::post('/attempts', [CheckoutAttemptController::class, 'store'])->middleware('throttle:'.CheckoutRateLimits::ATTEMPTS)->name('attempts.store');
        Route::post('/attempts/continue', [CheckoutAttemptController::class, 'continue'])->middleware('throttle:'.CheckoutRateLimits::CONTINUE)->name('attempts.continue');

        // ADR-0051: the sandbox bank of the Stripe.js stub (local/testing only).
        if (SandboxMode::enabled()) {
            Route::post('/sandbox/next-action', SandboxNextActionController::class)->middleware('throttle:'.CheckoutRateLimits::CONTINUE)->name('sandbox.next-action');
        }
    });

// Anything else on the pay host (malformed or too long tokens included):
// the same 404 page as an unknown token (plan 11.2).
Route::fallback(static fn (CheckoutResponses $responses) => $responses->notFound(request()->expectsJson()))->name('checkout.fallback');
