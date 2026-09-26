<?php

declare(strict_types=1);

use App\Modules\ProviderEvents\Http\Controllers\StripeConnectWebhookController;
use App\Modules\ProviderEvents\Http\Controllers\StripeDirectWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Incoming gateway webhooks (plan 14.1)
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php on the API host, outside `/v1` (they are
| not part of the public API), with the `api` middleware group only: no
| session, no CSRF, no API-key authentication. The signature is the
| authentication. Generous rate limit: Stripe retries and bursts.
|
*/

Route::middleware('throttle:1200,1')->group(function (): void {
    Route::post('/webhooks/stripe/connect/{mode}', StripeConnectWebhookController::class)
        ->whereIn('mode', ['test', 'live'])
        ->name('webhooks.stripe.connect');

    Route::post('/webhooks/stripe/direct/{connection}', StripeDirectWebhookController::class)
        ->where('connection', '[0-9A-Za-z]{26}')
        ->name('webhooks.stripe.direct');
});
