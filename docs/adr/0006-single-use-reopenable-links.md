# ADR-0006: Link semantics: single use, reopenable

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-006

## Context

Reusable links (multiple payments) and single-use links were evaluated.

## Options considered

1. Reusable links (multiple payments, `max_uses`).
2. **Single-use links.**

## Decision

**Single use.** A link can be opened as many times as wanted while it is valid and unpaid. It becomes unusable only when it is paid, expires or is canceled.

## Rationale

Simplicity of the MVP; the rules below prevent double charges when a link is opened in several tabs or devices.

## Consequences

- A failed payment does **not** consume the link.
- While an attempt is `processing` or `requires_action`, no parallel attempt may start.
- **At most one active (non-terminal) PaymentIntent per link.** One in `requires_payment_method` is reused or updated instead of creating another.
- **If a payment succeeds after expiration or cancellation, the payment wins**: the link becomes `paid` and the anomaly is recorded.
- Reusable links are out of the MVP; the data model (link 1:N attempts) does not prevent them later.
