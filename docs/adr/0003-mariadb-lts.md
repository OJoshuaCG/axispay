# ADR-0003: Database engine: self-managed MariaDB LTS

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-003

## Context

PostgreSQL was evaluated for Row-Level Security (RLS). The team decided on MariaDB on its own servers.

## Options considered

1. PostgreSQL (with RLS).
2. MySQL 8.x.
3. **MariaDB LTS.**

## Decision

MariaDB, the most recent LTS available at project start (11.4 LTS or 11.8 LTS), with the **same exact version** in local, CI, staging and production. Phase 0 pins 11.8 (see ADR-026).

## Rationale

Team decision in favor of a self-managed MariaDB; the missing RLS is compensated by the controls of plan section 6.

## Consequences

- No RLS: the compensating controls of plan section 6 apply.
- Charset `utf8mb4`, default collation `utf8mb4_uca1400_ai_ci`. `utf8mb4_0900_ai_ci` is MySQL-only and does not exist in MariaDB.
- Binary collation (`ascii_bin`) for tokens, hashes, idempotency keys and external IDs (plan section 6.7).
- JSON in MariaDB is an alias of `LONGTEXT` with `JSON_VALID`; do not rely on MySQL-specific JSON functions.
- `SKIP LOCKED` is available since 10.6, which enables the database queue.
- The team is fully responsible for backups, PITR, upgrades, TLS and monitoring (plan section 25).
