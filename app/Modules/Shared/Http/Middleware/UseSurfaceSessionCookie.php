<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives each surface its own session cookie name (ADR-0034, plan 4.1): the
 * admin and app hosts never read or overwrite each other's session, even if a
 * browser were ever sent a cookie for a shared parent domain. Global and
 * prepended, so it runs before StartSession on every route.
 */
final readonly class UseSurfaceSessionCookie
{
    public function __construct(private SessionManager $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = self::cookieFor($request->getHost());

        if ($name !== null) {
            config(['session.cookie' => $name]);
            // The store may already exist (long-lived workers, tests).
            $store = $this->sessions->driver();

            if ($store instanceof Session) {
                $store->setName($name);
            }
        }

        return $next($request);
    }

    public static function cookieFor(string $host): ?string
    {
        $surfaces = config('axispay.surfaces');
        $cookies = config('axispay.session_cookies');

        if (! is_array($surfaces) || ! is_array($cookies)) {
            return null;
        }

        foreach ($cookies as $surface => $cookie) {
            if (is_string($cookie) && ($surfaces[$surface] ?? null) === $host) {
                return $cookie;
            }
        }

        return null;
    }
}
