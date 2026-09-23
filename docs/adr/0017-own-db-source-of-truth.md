# ADR-0017: Payments source of truth: own database fed by webhooks + reconciliation

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-017

## Context

The panel and the API must show payment state reliably even when webhooks are lost or arrive out of order.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

The panel and the API read from our database; Stripe is **not** queried to render views. Stripe webhooks update state and a periodic reconciliation job corrects missed events. Every webhook handler **re-fetches the object from Stripe** before updating.

## Rationale

Re-fetching resolves event ordering problems; at the expected volume its cost is negligible.

## Consequences

- Plan sections 12.5 and 14 define reconciliation and webhook processing.
