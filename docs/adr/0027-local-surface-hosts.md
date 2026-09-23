# ADR-0027: Surface hosts for local development

- **Status:** Accepted (by project owner delegation, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** master plan section 4.1

## Context

All surfaces are served by one Laravel application, separated by domain with `Route::domain(...)`, with session cookies isolated per subdomain (plan 4.1). Developers need the same separation locally without editing `/etc/hosts` for every machine.

## Options considered

1. **`*.localhost` hosts** (`admin.localhost`, `app.localhost`, `pay.localhost`, `api.localhost`). Browsers and most resolvers map `*.localhost` to the loopback address without configuration.
2. **Path prefixes** (`/admin`, `/api`). Breaks cookie isolation and diverges from production routing.
3. **A custom local domain** (`*.paylink.test`). Needs DNS or hosts-file setup per machine.

## Decision

Local hosts default to `admin.localhost`, `app.localhost`, `pay.localhost` and `api.localhost`, configurable through `PAYLINK_ADMIN_HOST`, `PAYLINK_APP_HOST`, `PAYLINK_PAY_HOST` and `PAYLINK_API_HOST` (`config/paylink.php` → `surfaces`). Production sets them to the real subdomains.

## Rationale

Zero-setup locally and the same routing model as production.

## Consequences

- Phase 0 routes the public API (`/v1`) only on the API host; the API error envelope (plan 10.4) applies only to requests on that host, so web and panel errors keep their HTML rendering.
- Phase 1 moves the panels onto their hosts; the checkout moves to the pay host in Phase 4.
- `php artisan serve` listens on one port, so local URLs look like `http://api.localhost:8000/v1/...`.
