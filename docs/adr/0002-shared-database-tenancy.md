# ADR-0002: Tenancy: one shared database with tenant_id

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-002

## Context

The initial idea was one database per customer to isolate their data.

## Options considered

1. **Database per tenant.** Pros: strong isolation, per-customer restore. Cons: N migrations, connection explosion, per-customer provisioning, federated global metrics and a fixed cost per customer. A central database is needed anyway (Stripe webhook routing, API keys, public tokens).
2. **Schema per tenant.** In MariaDB a schema is a database: same problems as the previous option.
3. **Shared database with a `tenant_id` column**, isolation in the application, reinforced in the database with composite foreign keys.
4. **Hybrid pool + silo** for enterprise customers.

## Decision

Shared database with `tenant_id` (option 3). The design must not prevent evolving to the hybrid pool + silo model in the future.

## Rationale

Operational simplicity and cost. The requirement was to isolate the information, not a regulatory requirement for physical separation.

## Consequences

- Isolation is the code's responsibility, so the controls of plan section 6 are mandatory (fail-closed tenant scope, composite foreign keys, CI checks, isolation tests).
