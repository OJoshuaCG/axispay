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
 *
 * One exception, the merchant's logo (ADR-0056 part B): a controller that
 * serves a versioned file whose bytes never change for that URL sets the
 * IMMUTABLE_ASSET request attribute; a successful answer then gets a
 * year-long public cache and a CSP that forbids everything (it is an image,
 * never a document). Its errors keep the page headers.
 */
final readonly class CheckoutSecurityHeaders
{
    public const string IMMUTABLE_ASSET = 'checkout.immutable_asset';

    public function __construct(private CheckoutContentSecurityPolicy $csp) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $immutable = $response->getStatusCode() === 200 && $request->attributes->getBoolean(self::IMMUTABLE_ASSET);

        $response->headers->set('Content-Security-Policy', $immutable ? "default-src 'none'; sandbox" : $this->csp->header($nonce));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        $response->headers->set('Cache-Control', $immutable ? 'public, max-age=31536000, immutable' : 'no-store, private');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }
}
