<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errors on the pay host (ADR-0051): the payer never sees framework text.
 * An expired session (CSRF, 419) answers the page script with
 * `session_expired`; not found, too many requests, server errors and
 * maintenance answer the checkout's own pages (or a short JSON outcome for
 * the script). Other hosts keep their own error handling (answers null).
 */
final class CheckoutErrorPages
{
    public static function render(Throwable $e, Request $request): JsonResponse|Response|null
    {
        if ($request->getHost() !== config()->string('axispay.surfaces.pay')) {
            return null;
        }

        $status = match (true) {
            $e instanceof TokenMismatchException => 419,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => config()->boolean('app.debug') ? null : 500,
        };

        if (! in_array($status, [404, 419, 429, 500, 503], true)) {
            return null;
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        if ($request->expectsJson()) {
            return new JsonResponse(match ($status) {
                419 => ['outcome' => 'session_expired', 'message' => __('checkout.messages.session_expired')],
                404 => ['outcome' => 'not_found'],
                429 => ['outcome' => 'too_many_requests'],
                default => ['outcome' => 'error', 'message' => __('checkout.messages.error')],
            }, $status, $headers);
        }

        $view = match ($status) {
            404 => View::make('checkout.not-found'),
            429 => View::make('checkout.too-many-requests'),
            default => View::make('checkout.error', ['status' => $status]),
        };

        return new Response($view->render(), $status, $headers);
    }
}
