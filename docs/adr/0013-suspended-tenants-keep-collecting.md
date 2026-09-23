# ADR-0013: Tenant suspension: existing links keep collecting

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-013

## Context

A tenant can be suspended (for example, for non-payment).

## Options considered

The master plan records this decision without listing alternative options.

## Decision

A `suspended` tenant **cannot create new links** (API and panel), but **its valid links keep working** until paid or expired. The panel stays accessible read-only, with a notice.

## Rationale

Payers are not to blame for the tenant's non-compliance, and cutting issued links damages the platform's reputation.

## Consequences

- States and behavior matrix in plan section 21.
