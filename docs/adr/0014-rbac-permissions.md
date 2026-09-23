# ADR-0014: RBAC with platform permissions; roles as permission sets

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-014

## Context

Tenant users need differentiated access, and platform operators need a separate, stronger access path.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

Code checks **permissions**, never role names. Roles are sets of permissions defined in the database (seeders) and editable by the superadmin without deploying code. Implemented with `spatie/laravel-permission` and its *teams* feature (team = tenant). **Separate superadmin:** `platform_admins` table, `platform` guard and its own subdomain; it is never "one more role" among tenant users.

## Rationale

Changing who can do what must not require code changes, and platform access must never be reachable through tenant roles.

## Consequences

- Plan section 17 defines the permission catalog, system roles and access rules.
