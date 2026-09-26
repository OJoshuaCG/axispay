<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\IdempotencyRequirement;
use App\Modules\ApiKeys\Http\Middleware\AuthenticateApiKey;
use App\Modules\ApiKeys\Http\Middleware\RequireApiScope;
use App\Modules\ApiKeys\Http\Middleware\ThrottleApiKey;
use App\Modules\PaymentLinks\Http\Controllers\PaymentLinkController;
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
});
