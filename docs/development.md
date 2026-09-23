# Local development

This guide gets the application running locally with the same database the
tests and CI use. Read `rules.md` and `docs/plans/master.md` before changing
code, and `docs/frontend/README.md` before touching UI.

## Requirements

- PHP 8.4 or 8.5 with `pdo_mysql`, `intl` and `mbstring`.
- Composer 2.
- Node.js with pnpm (the repository ships `pnpm-lock.yaml`).
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

3. Check the database keys in `.env` (see below) and migrate:

   ```sh
   php artisan migrate
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
| `LOG_STACK` | `single` | Use `json` in production (structured, redacted, rotated). |
| `SENTRY_LARAVEL_DSN` | empty | Error tracking stays off while empty (ADR-0029). |

The local credentials are defined in `compose.yaml` and the init script. They
are not secrets and must never be reused outside Docker.

The public API answers on the API host only, for example
`http://api.localhost:8000/v1/...`. Browsers resolve `*.localhost` to the
loopback address without extra configuration.

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
  `php artisan migrate --force --database=mariadb_migrator`.

## Continuous integration

`.github/workflows/ci.yml` runs Pint, Larastan and Pest on PHP 8.4 and 8.5
against a `mariadb:11.8.9` service configured like `docker/mariadb/conf.d`.
When MariaDB is upgraded, change `compose.yaml` and the workflow together.
