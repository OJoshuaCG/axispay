# ADR-0008: Outgoing webhooks: HMAC-SHA256 following Standard Webhooks

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-008

## Context

Tenants need to verify that webhooks come from the platform.

## Options considered

1. **HMAC-SHA256 with timestamp** (Stripe / Standard Webhooks style). Simple and widely known.
2. **Asymmetric Ed25519 signature** with a published public key. More robust against leaks on the client side, but less familiar to small integrators.
3. **mTLS.** Very strong, but heavy to operate for small merchants.
4. Complement: *fetch-back* pattern ("thin events"), where the receiver confirms the state by querying our API.

## Decision

**HMAC only** in the MVP, using the Standard Webhooks format (headers `webhook-id`, `webhook-timestamp`, `webhook-signature`). The fetch-back pattern is documented and recommended. Ed25519 is a possible future phase; the chosen format supports it without breaking the contract.

## Rationale

Familiarity and simplicity for small integrators while keeping the door open for asymmetric signatures.

## Consequences

- Plan section 15 defines signing, delivery, retries and SSRF protection.
