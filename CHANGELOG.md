# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

- Minimum PHP version raised from 8.3 to 8.4 (required by Pest 5; the plan prefers 8.4).
- Tests run against MariaDB instead of in-memory SQLite.
- `declare(strict_types=1);` added to every PHP file.
- `rules.md` now points to `docs/plans/master.md`.
