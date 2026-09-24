# Local development

This guide gets the application running locally with the same database the
tests and CI use. Read `rules.md` and `docs/plans/master.md` before changing
code, and `docs/frontend/README.md` before touching UI. For production see
[deployment/dokploy.md](deployment/dokploy.md); for how the pieces fit, see
[architecture.md](architecture.md).

## Requirements

- PHP 8.4 or 8.5 with `pdo_mysql`, `intl` and `mbstring`.
- Composer 2.
- Node.js 22 with pnpm; the version is pinned in `package.json` →
  `packageManager` (`corepack enable` picks it up). pnpm is the only package
  manager: the repository ships `pnpm-lock.yaml` and no `package-lock.json`.
- Docker with Docker Compose.

## First-time setup

1. Start MariaDB 11.8 (host port 33061 by default):

   ```sh
   docker compose up -d
   docker compose ps   # wait until the mariadb service is "healthy"
   ```

   On the first start, `docker/mariadb/initdb.d/01-databases-and-users.sql`
   creates the `paylink` and `paylink_testing` databases and the `paylink_app`
   and `paylink_migrator` users. Init scripts only run on an empty volume; to
   rerun them, remove the volume with `docker compose down -v` (this deletes
   local data).

2. Install dependencies and create `.env`:

   ```sh
   composer install
   cp .env.example .env
   php artisan key:generate
   pnpm install
   pnpm run build
   ```

3. Check the database keys in `.env` (see below; `DB_CONNECTION` must be
   `mariadb`), migrate and seed the permission catalog:

   ```sh
   php artisan migrate --seed
   php artisan db:seed --class=DevelopmentSeeder   # local demo accounts, APP_ENV=local only
   ```

4. Run the app and the asset server:

   ```sh
   pnpm run dev
   php artisan serve
   ```

## Environment keys

| Key | Local value | Notes |
|---|---|---|
| `DB_CONNECTION` | `mariadb` | Only MariaDB is supported. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `33061` | Production: the external database server. |
| `DB_DATABASE` | `paylink` | Tests use `paylink_testing` (set in `phpunit.xml`). |
| `DB_USERNAME` / `DB_PASSWORD` | `paylink_app` / `paylink_app` | Runtime user (DML only in production). |
| `DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD` | `paylink_migrator` / `paylink_migrator` | Used by `--database=mariadb_migrator`. |
| `MYSQL_ATTR_SSL_CA`, `MYSQL_ATTR_SSL_CERT`, `MYSQL_ATTR_SSL_KEY`, `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` | unset | Optional TLS to an external server. |
| `PAYLINK_API_HOST`, `PAYLINK_APP_HOST`, `PAYLINK_ADMIN_HOST`, `PAYLINK_PAY_HOST` | `*.localhost` | Surface hosts (ADR-0027). |
| `PAYLINK_PASSWORD_CHECK_UNCOMPROMISED` | `true` (default) | Breached-password check on new passwords (HIBP). Off in `phpunit.xml`. |
| `LOG_STACK` | `single` | The production image logs redacted JSON to stderr instead (ADR-0035). |
| `TRUSTED_PROXIES` | unset | Production behind Traefik: its network range (`config/trustedproxy.php`). |
| `SENTRY_LARAVEL_DSN` | empty | Error tracking stays off while empty (ADR-0029). |

The local credentials are defined in `compose.yaml` and the init script. They
are not secrets and must never be reused outside Docker.

The public API answers on the API host only, for example
`http://api.localhost:8000/v1/...`. Browsers resolve `*.localhost` to the
loopback address without extra configuration.

## Panels (Phase 1)

| Panel | Local URL | Guard | Who |
|---|---|---|---|
| Platform (`admin`) | `http://admin.localhost:8000` | `platform` | Platform admins (`platform_admins` table) |
| Tenant (`app`) | `http://app.localhost:8000` | `web` | Tenant users (`users` table) |

Browsers resolve `*.localhost` to 127.0.0.1; for `curl`, use
`--resolve app.localhost:8000:127.0.0.1` or a `Host` header. The two hosts
never share a session cookie.

### Local accounts (DevelopmentSeeder)

`php artisan db:seed --class=DevelopmentSeeder` refuses to run unless
`APP_ENV=local`. It prints and creates:

| Panel | E-mail | Password | Role |
|---|---|---|---|
| admin | `superadmin@paylink.test` | `local-dev-password` | Superadmin |
| app | `owner@demo.paylink.test` | `local-dev-password` | Owner of "Demo Company" (active) |

These are local-only, non-secret credentials. Never create them anywhere else.

### Two-factor authentication

- Mandatory for every platform admin and for tenant users with a sensitive
  permission (owner, admin, integration manager, finance). On first sign-in
  they are sent to the 2FA set-up page: scan the QR code with an authenticator
  app (TOTP) and store the recovery codes.
- Other users can enable it from **Profile**.
- Sensitive actions (e.g. changing roles) ask for the password or a current
  2FA code again; the confirmation lasts 10 minutes.

### Other flows

- **Invitations:** Users → Invite user. With `MAIL_MAILER=log`, the signed link
  is written to `storage/logs/laravel.log`; open it on the app host.
- **Impersonation:** admin panel → Tenants → a tenant → View as user (reason
  required, read-only, 30 minutes, audited). A banner in the tenant panel stops it.
- **Test/live selector:** the badge in the tenant panel topbar (test by default).
- **Reset 2FA of a local account** (lost authenticator, fresh seed):
  `php artisan paylink:dev-reset-2fa owner@demo.paylink.test` (works for tenant
  users and platform admins; refuses to run unless `APP_ENV=local`; audited).
  The next sign-in asks to set 2FA up again.
- **Sign-in throttling:** 5 failed attempts per account in 15 minutes lock that
  account's sign-in for the rest of the window (plus Filament's per-IP limit).
  Locally, clear it with `php artisan cache:clear`.
- **Session cookies:** each host has its own (`paylink_admin_session`,
  `paylink_app_session`); keep `SESSION_DOMAIN` empty or the app will not boot.
- **Platform admins in other environments:** `php artisan paylink:create-platform-admin`
  (interactive; the password is never passed as an argument).

## Quality checks

| Command | What it does |
|---|---|
| `composer test` | Pest suite against `paylink_testing` on the Docker MariaDB. |
| `composer analyse` | Larastan, PHPStan level max. |
| `composer format` | Fixes code style with Pint. |
| `composer format:check` | Checks code style without changing files. |
| `composer ci` | `format:check`, `analyse` and `test`, as CI runs them. |

The test suite needs the MariaDB container running. Its connection settings
live in `phpunit.xml` (`<env>` entries), so they do not depend on `.env`.

## Multi-tenancy rules in practice

- Every model on a table with `tenant_id` uses `BelongsToTenant`; list the
  table in `config/tenancy.php` (a test enforces both).
- Never `DB::table('<tenant table>')`, raw SQL on tenant tables or
  `withoutGlobalScope(s)` outside the whitelist in `config/tenancy.php`:
  PHPStan fails (`paylink.tenantTableAccess`, `paylink.rawTenantSql`,
  `paylink.scopeBypass`).
- Cross-tenant work: `TenantContext::runAsTenant()` per tenant, or
  `runAsPlatform($reason, ...)` (audited).
- Queued tenant jobs implement `TenantAware` and use `CapturesTenantContext`.
- New tenant-panel resources need an entry in the isolation dataset
  (`tests/Feature/Isolation/TenantIsolationTest.php`); the route coverage test
  fails otherwise.

## Database conventions

- Default charset and collation: `utf8mb4` / `utf8mb4_uca1400_ai_ci`.
- ULIDs, tokens, hashes, idempotency keys and provider IDs use `ascii_bin`
  through the schema macros: `$table->ulidAscii('id')->primary()`,
  `$table->foreignUlidAscii('tenant_id')`, `$table->asciiString('idempotency_key')`,
  `$table->asciiChar('key_hash', 64)`, `->asciiBin()`.
- Business datetimes use `DATETIME(6)` in UTC: `$table->dateTime('paid_at', 6)`
  and `$table->datetimes(6)`, never `timestamp()`.
- Money is `BIGINT` minor units plus `CHAR(3)` currency; exchange rates are
  `DECIMAL(18,6)`. Never floats.
- Run migrations in production with the migrator user:
  `php artisan migrate --force --database=mariadb_migrator`. The production
  image does this at deploy time (`RUN_MIGRATIONS=true` on the web service).

## Continuous integration

`.github/workflows/ci.yml` runs Pint, Larastan and Pest on PHP 8.4 and 8.5
against a `mariadb:11.8.9` service configured like `docker/mariadb/conf.d`.
When MariaDB is upgraded, change `compose.yaml` and the workflow together.

## Production image

`docker build -t paylink:local .` builds the production image. To run its
roles against the local database, see "Run the production image locally" in
[deployment/dokploy.md](deployment/dokploy.md#run-the-production-image-locally).
