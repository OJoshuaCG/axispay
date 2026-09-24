# ADR-0035: Production container image (Nginx + PHP-FPM, one image, several roles)

- **Status:** Accepted (by project owner delegation, 2026-09-24)
- **Date:** 2026-09-24
- **Source:** master plan sections 5, 23.2–23.4, 24.1, 24.4, 25.1 and 25.4; ADR-0036

## Context

The project owner chose Dokploy to deploy the application (ADR-0036). Dokploy runs Docker containers, so the application needs a production image. The plan asks for Nginx + PHP-FPM (25.1) and forbids Octane (section 5): state that survives between requests could leak the tenant context. It also asks for at least two `queue:work` processes on the `critical`, `default` and `low` queues, `schedule:run` every minute, migrations run by a separate DDL user (25.5), cached config, routes, views and events (25.4), JSON logs with redaction (23.3) and secrets kept out of the repository (23.2).

## Options considered

1. **Nginx + PHP-FPM in one container** on the official `php:8.4-fpm` Debian image. This is the plan's stack. Each request runs in a fresh PHP request lifecycle.
2. **FrankenPHP in classic mode** (not worker mode). One process and no FastCGI hop. The request model is the same as FPM, but it moves away from the plan's stack for a small gain.
3. **Separate Nginx and PHP-FPM containers.** Nginx needs the public assets, so the two containers would have to share a volume or duplicate the image. Dokploy would also need two services per web deploy.
4. **A third-party base image** (for example `serversideup/php`). Less code to own, but the process supervisor, users and defaults belong to an external project.

## Decision

Option 1. The `Dockerfile` at the repository root builds a single image. `docker/app/` holds its Nginx, PHP-FPM, PHP and entrypoint files.

- **Build stages.**
  - `base` installs the extensions on `php:8.4-fpm-trixie`: `pdo_mysql`, `intl`, `bcmath`, `gmp`, `zip` and `pcntl`, with OPcache enabled.
  - `vendor` runs `composer install --no-dev` and `composer check-platform-reqs --no-dev`, so a missing extension fails the build. It also runs `package:discover` and `filament:assets`.
  - `assets` runs `pnpm install --frozen-lockfile` and `vite build`. pnpm is pinned through `package.json` → `packageManager`.
  - `runtime` copies the results.
- **Roles.** The first argument selects the role; without one, the `CONTAINER_ROLE` environment variable does, and the default is `web`.
  - `web`: Nginx on port 8080 and PHP-FPM, run by a bash supervisor under `tini`.
  - `worker`: `queue:work` on `QUEUE_NAMES`, default `critical,default,low`.
  - `scheduler`: `schedule:work`, which runs `schedule:run` every minute.
  - `release`: one-shot. It runs `migrate --force --database=mariadb_migrator` and then the idempotent `db:seed` (the permission catalog).
  - `artisan …`: one-off Artisan commands.
- **Migration ordering.** With `RUN_MIGRATIONS=true`, a long-running role runs the release steps before it starts. Otherwise it waits until `migrate:status --pending=1` reports no pending migration, so new code never runs against an old schema. The migrator credentials are removed from the environment before the runtime caches are built. The cached config and the PHP processes never hold them.
- **Runtime caches.** `config:cache`, `route:cache`, `view:cache` and `event:cache` run at container start, not at build. They depend on the environment: the hosts in `config/paylink.php` shape the routes.
- **Security.**
  - The process runs as `www-data`. The code is owned by root and read-only; only `storage/` and `bootstrap/cache` are writable.
  - No `.env` is present. The build checks this, and `.dockerignore` keeps `.env*`, `storage/`, `vendor/` and `node_modules/` out of the build context.
  - Nginx sends HSTS (`max-age=31536000; includeSubDomains`), `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy` and `Permissions-Policy`.
- **Proxy.** `config/trustedproxy.php` reads `TRUSTED_PROXIES`, and Laravel's `TrustProxies` middleware uses it. Behind Traefik, the application then sees HTTPS, the real host and the client IP. When the variable is unset, no proxy is trusted.
- **Logs.**
  - The image defaults to `LOG_CHANNEL=stderr` with `Monolog\Formatter\JsonFormatter`, and the `RedactSensitiveLogData` tap stays in place. PHP-FPM forwards worker output undecorated. No log file is written in the container.
  - Access logs are off in Nginx and PHP-FPM, because request URLs can carry single-use tokens (signed invitation and impersonation links).
- **Health.**
  - `HEALTHCHECK` runs `paylink-healthcheck`, which depends on the role. `web` requests `/up` on 127.0.0.1. `/up` is registered without `Route::domain()`, so it answers on any Host. `worker` and `scheduler` check that their Artisan process is alive.
- **Shutdown.**
  - On SIGTERM, `web` sends SIGQUIT to Nginx and FPM, which drain gracefully. FPM waits for running requests up to `process_control_timeout = 25s`.
  - `queue:work`, which needs `pcntl`, finishes the current job and then exits. The orchestrator's stop grace period must exceed `QUEUE_TIMEOUT` (default 60 s). ADR-0036 recommends 90 s.
- **OPcache.** It is tuned for immutable code (`validate_timestamps=0`, 192 MB, 30000 files), and JIT is off. A new release is a new container.

## Rationale

Option 1 matches the plan's stack. It keeps PHP's request isolation, which is the reason Octane was rejected. One container per role keeps the web deploy to a single service. One image for every role guarantees that the web, workers, scheduler and migrations run the same code.

## Consequences

- **Supervisor is replaced by the orchestrator** (plan 5 and 25.1). A worker is a container, and "at least two processes" means two containers or replicas (ADR-0036).
- **`queue:restart` is not used.** A redeploy replaces the worker containers, and each worker finishes its current job on SIGTERM.
  - If someone runs `queue:restart` by hand, the workers exit with status 0, and the orchestrator must restart them.
  - `--memory` exits with a non-zero status, so any restart policy recovers from it.
- **The image is about 900 MB** (Debian base plus build tooling kept by the official image). A slimmer Alpine or distroless variant is a possible later optimization. It is not needed for correctness.
- **Plan 24.4 is still open.** `/health/live` and `/health/ready`, where ready checks the database, queue age and scheduler heartbeat, are not implemented. `/up` covers liveness until they are.
- **The checkout's strict CSP (plan 11.8)** arrives with the checkout in Phase 4. The Nginx headers apply to every surface.
- **HSTS preload is not enabled.** Submitting the domain to the preload list affects every subdomain of the apex domain, so it is a decision for the owner of that domain (plan 23.4).
- **The Nginx error log** can include a request line on errors. It is not an access log, and it does not record query strings of successful requests.
