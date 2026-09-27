<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Middleware;

use App\Modules\Checkout\Services\CheckoutContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers of every checkout response (plan 11.8, 23.4):
 * nonce-based CSP (the nonce is also given to Vite, so `@vite` and `@fonts`
 * tags carry it), no framing, no Referer (the link token is in the URL), no
 * caching of dynamic pages, no indexing. HSTS is set here with the
 * checkout's stricter value; nginx only adds its own when the application
 * did not (docker/app/nginx.conf).
 */
final readonly class CheckoutSecurityHeaders
{
    public function __construct(private CheckoutContentSecurityPolicy $csp) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->csp->header($nonce));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }
}
