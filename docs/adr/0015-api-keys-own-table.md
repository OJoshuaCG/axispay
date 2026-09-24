# ADR-0015: API keys in their own table, owned by the tenant (not a user)

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-015

## Context

Integrators authenticate against the API with keys.

## Options considered

1. **Laravel Sanctum with `tokenable = Tenant`.** Works, but separating test/live with distinct visible prefixes requires forcing it with a custom model.
2. **Own `api_keys` table** with per-mode prefixes (`axp_test_` / `axp_live_`), SHA-256 hash, scopes, optional expiration, `last_used_at` and revocation.

## Decision

Own table (option 2). It is little code (a model, a generator and a middleware) and gives full control.

## Rationale

The key belongs to the tenant, so the integration does not break when the user who created it leaves the company. Visible prefixes prevent using production keys in tests by accident.

## Consequences

- Plan section 10.2 defines the key format, hashing and scopes.
