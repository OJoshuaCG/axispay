<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocale;
use App\Modules\Checkout\Http\CheckoutErrorPages;
use App\Modules\Checkout\Http\Middleware\ApplyCheckoutLocale;
use App\Modules\Checkout\Http\Middleware\CheckoutSecurityHeaders;
use App\Modules\Shared\Http\Errors\ApiErrorRenderer;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Http\Errors\ApiSurface;
use App\Modules\Shared\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Http\Middleware\UseSurfaceSessionCookie;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Public API v1, served only on the API host (ADR-027, plan 10.1).
            Route::middleware('api')
                ->domain(ApiSurface::host())
                ->prefix('v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));

            // Public checkout (plan 11.5): pay host, web group (anonymous
            // session + CSRF), ADR-0051.
            // The security headers wrap the whole group (listed before `web`),
            // so session, CSRF (419) and not-found answers get them too.
            Route::middleware([CheckoutSecurityHeaders::class, 'web'])
                ->domain(config()->string('axispay.surfaces.pay'))
                ->group(base_path('routes/checkout.php'));

            // Incoming gateway webhooks (plan 14.1): API host, outside /v1.
            Route::middleware('api')
                ->domain(ApiSurface::host())
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before StartSession: per-surface session cookie name (ADR-0034).
        $middleware->prepend(UseSurfaceSessionCookie::class);

        // First global middleware, so every response and log line (including
        // errors raised by later middleware) carries the request ID.
        $middleware->prepend(AssignRequestId::class);

        // Runs after EncryptCookies/StartSession, so the `locale` cookie is
        // already decrypted. See docs/frontend/i18n.md for the resolution order.
        $middleware->web(append: [
            SetLocale::class,
        ]);

        // Checkout: the link's language before the request limits answer (ADR-0051).
        $middleware->prependToPriorityList(ThrottleRequests::class, ApplyCheckoutLocale::class);

        // Non-Filament routes that need a tenant user (app host) send guests
        // to the tenant panel's sign-in page.
        $middleware->redirectGuestsTo(static fn (): string => route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports unhandled exceptions to Sentry/GlitchTip. No-op without a DSN.
        Integration::handles($exceptions);

        // Client errors are expected API outcomes, not incidents.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof ApiException && $e->status() < 500,
        );

        // Pay host only: the checkout's own error pages and outcomes (ADR-0051).
        $exceptions->render(
            fn (Throwable $e, Request $request) => CheckoutErrorPages::render($e, $request),
        );

        // API surface only: the error envelope of plan section 10.4. Returning
        // null leaves web and panel errors to Laravel's default rendering.
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiSurface::matches($request)
                ? app(ApiErrorRenderer::class)->render($e)
                : null,
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
