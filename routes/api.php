<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\IdempotencyRequirement;
use App\Modules\ApiKeys\Http\Middleware\AuthenticateApiKey;
use App\Modules\ApiKeys\Http\Middleware\RequireApiScope;
use App\Modules\ApiKeys\Http\Middleware\ThrottleApiKey;
use App\Modules\PaymentLinks\Http\Controllers\PaymentLinkController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\RefundController;
use App\Modules\Webhooks\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php on the API host with the `/v1` prefix and
| the `api` middleware group. Keep docs/api/openapi.yaml in sync with every
| route added here, and add each route to the API isolation dataset
| (tests/Feature/Isolation).
|
| Order: API key (sets the tenant and mode) -> rate limit per key -> scope
| -> idempotency (POST) -> controller.
|
*/

Route::middleware([AuthenticateApiKey::class, ThrottleApiKey::class])->group(function (): void {
    Route::post('/payment_links', [PaymentLinkController::class, 'store'])
        ->middleware([RequireApiScope::class.':links:create', IdempotencyRequirement::Required->middleware()])
        ->name('payment_links.store');

    Route::get('/payment_links', [PaymentLinkController::class, 'index'])
        ->middleware(RequireApiScope::class.':links:read')
        ->name('payment_links.index');

    Route::get('/payment_links/{id}', [PaymentLinkController::class, 'show'])
        ->middleware(RequireApiScope::class.':links:read')
        ->name('payment_links.show');

    Route::post('/payment_links/{id}/cancel', [PaymentLinkController::class, 'cancel'])
        ->middleware([RequireApiScope::class.':links:cancel', IdempotencyRequirement::Optional->middleware()])
        ->name('payment_links.cancel');

    Route::get('/payments', [PaymentController::class, 'index'])
        ->middleware(RequireApiScope::class.':payments:read')
        ->name('payments.index');

    Route::get('/payments/{id}', [PaymentController::class, 'show'])
        ->middleware(RequireApiScope::class.':payments:read')
        ->name('payments.show');

    // Releases an authorization that was not captured: the scope of undoing a
    // charge, `refunds:create` (ADR-0066).
    Route::post('/payments/{id}/void', [PaymentController::class, 'void'])
        ->middleware([RequireApiScope::class.':refunds:create', IdempotencyRequirement::Optional->middleware()])
        ->name('payments.void');

    Route::post('/refunds', [RefundController::class, 'store'])
        ->middleware([RequireApiScope::class.':refunds:create', IdempotencyRequirement::Required->middleware()])
        ->name('refunds.store');

    Route::get('/refunds', [RefundController::class, 'index'])
        ->middleware(RequireApiScope::class.':refunds:read')
        ->name('refunds.index');

    Route::get('/refunds/{id}', [RefundController::class, 'show'])
        ->middleware(RequireApiScope::class.':refunds:read')
        ->name('refunds.show');

    Route::get('/events', [EventController::class, 'index'])
        ->middleware(RequireApiScope::class.':events:read')
        ->name('events.index');

    Route::get('/events/{id}', [EventController::class, 'show'])
        ->middleware(RequireApiScope::class.':events:read')
        ->name('events.show');
});
