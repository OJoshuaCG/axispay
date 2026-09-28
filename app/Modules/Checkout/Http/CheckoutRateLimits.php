<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;

/**
 * Request limits of the pay host (ADR-0051), one named limiter per group so
 * that polling or reloading never eats into the budget of paying. Keyed by
 * client IP and link token. These protect the server; the card-testing
 * rules (CheckoutRateLimiter) are separate and stricter.
 *
 *  - page (the payment page) and complete (the completion page, also the
 *    3D Secure return URL): 60 per minute each;
 *  - status (the completion page polls every 3 s for 2 minutes, 40 polls;
 *    two tabs fit): 90 per minute;
 *  - attempts (Pay) and continue (after 3D Secure): 30 per minute each.
 *
 * An exceeded limit answers 429 with `Retry-After`: JSON the page script
 * shows as a message, or a short page.
 */
final class CheckoutRateLimits
{
    public const string PAGE = 'checkout-page';

    public const string COMPLETE = 'checkout-complete';

    public const string STATUS = 'checkout-status';

    public const string ATTEMPTS = 'checkout-attempts';

    public const string CONTINUE = 'checkout-continue';

    public static function register(): void
    {
        foreach ([self::PAGE => 60, self::COMPLETE => 60, self::STATUS => 90, self::ATTEMPTS => 30, self::CONTINUE => 30] as $name => $perMinute) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute($perMinute)
                ->by($name.'|'.$request->ip().'|'.self::token($request))
                ->response(static fn (Request $request, array $headers) => self::tooMany($request, $headers)));
        }
    }

    private static function token(Request $request): string
    {
        $token = $request->route('token');

        return is_string($token) ? hash('sha256', $token) : '-';
    }

    /**
     * @param  array<mixed>  $headers
     */
    private static function tooMany(Request $request, array $headers): JsonResponse|Response
    {
        $retryAfter = $headers['Retry-After'] ?? 60;
        $seconds = max(1, is_numeric($retryAfter) ? (int) $retryAfter : 60);

        if ($request->expectsJson()) {
            return new JsonResponse([
                'outcome' => 'too_many_requests',
                'message' => __('checkout.messages.too_many_requests', ['seconds' => $seconds]),
                'retry_after_seconds' => $seconds,
            ], 429, $headers);
        }

        return new Response(View::make('checkout.too-many-requests', ['seconds' => $seconds])->render(), 429, $headers);
    }
}
