<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies (ADR-0035)
    |--------------------------------------------------------------------------
    |
    | Read by Laravel's TrustProxies middleware. In production TLS ends at the
    | reverse proxy (Traefik in Dokploy), so the application must trust the
    | proxy's X-Forwarded-For/Host/Port/Proto headers to see HTTPS, the real
    | host and the client IP. Comma-separated IPs or CIDR ranges of the proxy
    | network only; never `*` on a host whose port 8080 is reachable from the
    | internet. Unset (local development) trusts no proxy.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
