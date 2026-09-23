<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the interface locale for every web request.
 *
 * Priority (first valid value wins):
 *   1. ?lang=<code> query parameter  (explicit, persisted in the cookie)
 *   2. `locale` cookie               (a previous explicit choice)
 *   3. Accept-Language header        (best supported match)
 *   4. config('app.locale')
 *
 * Unsupported values are ignored at every step, never trusted.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $fromQuery = Locales::match($request->query(Locales::QUERY));
        $fromCookie = Locales::match($request->cookie(Locales::COOKIE));

        $locale = $fromQuery
            ?? $fromCookie
            ?? Locales::fromAcceptLanguage($request)
            ?? Locales::fallback();

        Locales::apply($locale);

        $response = $next($request);

        if ($fromQuery !== null && $fromQuery !== $fromCookie) {
            $response->headers->setCookie(Locales::cookie($fromQuery));
        }

        // The same URL renders differently per header/cookie: keep shared
        // caches from serving one viewer's language to another.
        $response->setVary(['Accept-Language', 'Cookie'], false);

        return $response;
    }
}
