# ADR-0011: Platform billing to its customers: outside the payment flow

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-011

## Context

The platform will charge a monthly subscription and/or a fixed fee per transaction and/or a percentage, fully customizable per tenant. **The payer is never charged.**

## Options considered

1. **`application_fee_amount`** of Stripe Connect on each charge + Stripe Billing for the subscription.
2. **External billing:** the platform computes usage and generates a report; invoicing and collection happen outside the system.

## Decision

External billing (option 2).

## Rationale

Keeps charges as clean direct charges and keeps billing flexible and outside the payment path.

## Consequences

- `application_fee_amount` is not used, so charges are clean direct charges.
- The platform keeps versioned pricing plans and generates reproducible monthly usage reports (plan section 21).
- The fixed per-transaction fee is not reversed on refunds; the percentage is computed on gross collected volume and is not reversed on refunds or disputes (ADR-012).
- VAT and the platform's invoicing to its customers are the operator's responsibility, outside the system.
