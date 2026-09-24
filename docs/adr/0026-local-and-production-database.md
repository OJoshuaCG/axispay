# ADR-0026: Local database in Docker; production on an external MariaDB server

- **Status:** Accepted (by project owner delegation, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** ADR-0003; master plan sections 5, 6.7, 6.8, 25.1 and 25.5

## Context

ADR-0003 requires the same exact MariaDB LTS version in every environment. Production runs on a dedicated, external MariaDB server that the application reaches with provided credentials, possibly across a network that needs TLS. Developers and CI need an equivalent server with the same charset, collation, `sql_mode` and time zone.

## Options considered

1. **Docker Compose with a pinned MariaDB image** for local work, the same image as a CI service, and env-driven connection settings for production.
2. **Laravel Sail.** Brings a PHP container we do not need locally and still requires the same MariaDB tuning.
3. **A locally installed MariaDB.** Version drift between machines.

## Decision

- Local: `compose.yaml` runs `mariadb:11.8.9` (11.8 LTS, exact patch pinned) on host port 33061, with `docker/mariadb/conf.d/axispay.cnf` (`utf8mb4`, `utf8mb4_uca1400_ai_ci`, strict `sql_mode`, `+00:00`, InnoDB) and an init script that creates `axispay` (development) and `axispay_testing` (tests), plus two users that model plan 25.5: `axispay_app` (runtime) and `axispay_migrator` (migrations). Locally both have full rights on both databases; production grants `axispay_app` DML only.
- CI: the same image tag as a service, configured with the same values through command-line flags.
- Production: the external server is reached exclusively through env settings (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD`) and optional TLS (`MYSQL_ATTR_SSL_CA`, `MYSQL_ATTR_SSL_CERT`, `MYSQL_ATTR_SSL_KEY`, `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`).
- The Laravel `mariadb` connection enforces charset, collation, the explicit `sql_mode` list, UTC and InnoDB per session, so correctness does not depend on server defaults. A `mariadb_migrator` connection runs migrations with the DDL user.

## Rationale

Pinning one image tag for local and CI and driving production purely by env is the simplest way to honor ADR-0003. Per-session enforcement protects against an external server whose defaults differ.

## Consequences

- **Open item:** confirm the exact MariaDB version of the external production server and align the pinned tag (local and CI) with it. Until confirmed, 11.8.9 is the reference.
- A test verifies charset, collation, `sql_mode` flags, time zone and engine against the real server.
- Upgrading MariaDB is a deliberate change: update `compose.yaml`, `.github/workflows/ci.yml` and production together.
