# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Production deployment (ADR-0035, ADR-0036):
  - Multi-stage `Dockerfile` (PHP 8.4 FPM + Nginx, Composer `--no-dev` with
    `check-platform-reqs`, pnpm + Vite build, non-root, no `.env`) and
    `.dockerignore`. One image, roles `web`, `worker`, `scheduler`, `release`
    and `artisan` selected by `CONTAINER_ROLE` or the first argument
    (`docker/app/entrypoint.sh`); role-aware `HEALTHCHECK`; runtime caches
    built at container start; migrations with the migrator connection before
    the web role serves (`RUN_MIGRATIONS=true`), other roles wait for them.
  - `config/trustedproxy.php` (`TRUSTED_PROXIES`) for TLS termination at a
    reverse proxy; security headers (HSTS, nosniff, frame options, referrer
    and permissions policies) in the image's Nginx.
  - `docs/deployment/dokploy.md`: step-by-step Dokploy guide (environments,
    variables, domains, Swarm health check and update settings, rollbacks,
    security checklist, troubleshooting).
  - Project documentation: `README.md`, `docs/README.md` (index) and
    `docs/architecture.md`.
- Phase 1 tenancy, identity and access (master plan section 27):
  - `Tenancy` module: `tenants` table and `TenantStatus` (plan 21.3) with
    `CreateTenant` / `ChangeTenantStatus` actions (superadmin only, reason,
    double confirmation to close, audited, owners notified); scoped
    `TenantContext` with audited `runAsPlatform()` and `runAsTenant()`;
    fail-closed `BelongsToTenant` and `BelongsToMode`; `TenantAware` jobs with
    `RestoreTenantContext`; tenant panel resolution and session test/live
    selector (`SwitchLivemode`, audited); `TenantSettings` DTO;
    `config/tenancy.php` as the single list of tenant tables.
  - Custom PHPStan rule (`Tests\PHPStan\Rules\TenantTableAccessRule`) against
    `DB::table()` / raw SQL on tenant tables and non-whitelisted
    `withoutGlobalScope(s)`, with rule tests.
  - Isolation test infrastructure: `assertTenantIsolation()`, a resource
    dataset, a route coverage test and a tenant-model inventory test.
  - `Identity` module: ULID tenant users (one tenant per user, global e-mail
    uniqueness), sign-in on the app host, TOTP 2FA with recovery codes
    (Filament MFA, encrypted, audited), invitations (signed, 72 h, single-use),
    re-authentication (password or 2FA code, 10 minutes), user deactivation.
  - `Access` module: spatie/laravel-permission 8 with teams (team = tenant),
    permission catalog and system roles seeders, permission-only policies,
    role changes with re-authentication, last-owner and no-escalation rules,
    sensitive-role notifications.
  - `PlatformAdmin` module: `platform_admins` and the `platform` guard on the
    admin host with mandatory 2FA, audited cross-host impersonation (read-only,
    30 minutes, banner), `paylink:create-platform-admin` command.
  - `Audit` module: append-only `audit_logs` (model guards and database
    triggers), redacted details, request metadata; records sign-ins, failed
    sign-ins, 2FA changes, invitations, role changes, tenant creation and status
    changes, impersonation and platform-context entries.
  - Filament 5.8 panels `admin` and `app`, themed from the design tokens
    (palettes built from `primitives.css`, self-hosted Jost, dark-mode bridge),
    EN/ES with a language switcher; resources: tenants, platform admins, audit
    log (admin); users, roles, audit log, profile (app).
  - `DevelopmentSeeder` (local only) and ADR-0030 to ADR-0033.
  - Security hardening after review (ADR-0034): shared `RoleGrantGuard` for
    role changes, invitations (re-checked on acceptance) and
    deactivation/reactivation; re-authentication for sensitive invitations and
    (de)activation; tenant row lock for owner invariants; per-host session
    cookies with a boot guard against a shared `SESSION_DOMAIN`; wider PHPStan
    tenancy rule; impersonation re-validates the platform admin on every
    request; per-account login throttling; per-tenant invitation throttling
    with audited refusals; local-only `paylink:dev-reset-2fa`.
- Phase 0 foundations (master plan section 27):
  - Tooling: Pest 5 (on PHPUnit 13), Larastan 3 at PHPStan level max, Pint with the
    Laravel preset plus `declare_strict_types` and strict comparison rules, and the
    Composer scripts `test`, `analyse`, `format`, `format:check` and `ci`.
  - Local MariaDB 11.8 LTS (`mariadb:11.8.9`) in `compose.yaml` with `utf8mb4` /
    `utf8mb4_uca1400_ai_ci`, strict `sql_mode`, UTC and InnoDB; `paylink` and
    `paylink_testing` databases; `paylink_app` and `paylink_migrator` users.
  - `mariadb` connection with explicit charset, collation, `sql_mode`, time zone,
    engine and optional TLS; `mariadb_migrator` connection for deploy-time migrations.
  - `app/Modules` structure with the `Shared` module: money value objects on
    brick/money (string parsing, minor units, HALF_UP rounding, DECIMAL(18,6)
    exchange rates), prefixed ULID identifiers, ULID model traits and schema
    macros for `ascii_bin` columns, API error envelope and `ApiErrorCode` catalog,
    `Request-Id` middleware.
  - Structured JSON log channel and secret/PII redaction on every log channel.
  - `sentry/sentry-laravel`, inert until `SENTRY_LARAVEL_DSN` is set, with an event
    scrubber that reuses the log redactor (Sentry or GlitchTip).
  - ADR-0001 to ADR-0024 from the master plan, and ADR-0025 to ADR-0029.
  - OpenAPI 3.1 skeleton (`docs/api/openapi.yaml`), `docs/development.md`, and a
    GitHub Actions CI workflow on PHP 8.4 and 8.5 with a MariaDB 11.8 service.

### Changed

- pnpm is the only package manager: its version is pinned in
  `package.json` → `packageManager` (used by CI and the Dockerfile), and the
  `composer setup` script uses pnpm instead of npm.
- Production logging guidance: the container logs redacted JSON to stderr
  (`LOG_CHANNEL=stderr` with the JSON formatter) instead of rotated files.
- The default `users` migration now creates tenants and tenant users with ULID
  keys and DATETIME(6); `sessions.user_id` is a `char(26)` ULID.
- `App\Models\User` moved to `App\Modules\Identity\Models\User`.
- `<x-language-switcher>` accepts an optional `redirect` path.
- `.atl/` and Filament's published assets are gitignored; `filament:upgrade`
  runs on `post-autoload-dump`.
- Minimum PHP version raised from 8.3 to 8.4 (required by Pest 5; the plan prefers 8.4).
- Tests run against MariaDB instead of in-memory SQLite.
- `declare(strict_types=1);` added to every PHP file.
- `rules.md` now points to `docs/plans/master.md`.
