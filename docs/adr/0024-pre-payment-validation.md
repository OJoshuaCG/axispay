# ADR-0024: Pre-payment validation: synchronous callback, separate from webhooks

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-024

## Context

The project owner wants the platform to ask the tenant's system, before charging, to validate the operation (stock, current price, order still pending, etc.). If validation fails, no charge happens; if it passes, the charge happens and later webhooks only notify. It is not a webhook: webhooks are asynchronous, retried for up to ~27 hours, delivered at least once and carry no meaningful response. The validation is **synchronous** (the payer is waiting), needs a **response with a decision** and does not allow long retries.

## Options considered

1. **When the link is opened.** Cons: the validation becomes stale if the payer takes time, and page load is blocked by the merchant's server.
2. **Right before confirming the charge** (after capturing the card, applying rate limits and Turnstile, and the FX confirmation if any). Pros: last real opportunity to stop the charge, with complete data.
3. **Both.**

## Decision

Right before confirming the charge (option 2) in the MVP. Option 1 is a possible future phase; meanwhile the async `payment_link.opened` event exists and the integrator can cancel a link through the API at any time.

## Rationale

It is the last point where the charge can really be stopped, and the tenant receives the final amount.

## Consequences

- Optional per tenant and switchable per link (`pre_payment_validation: true|false`, with the tenant default).
- URL configured in the panel (one per mode), signed with the Standard Webhooks HMAC scheme and protected by the same SSRF rules.
- Explicit response contract: `approve` or `reject` (with an optional code and payer message).
- **Total timeout of 5 seconds.** No retries (except one immediate retry on a connection failure before sending data).
- Failure policy (timeout, error, invalid response) configurable per tenant: `fail_closed` (**default**, no charge) or `fail_open` (charge anyway and record it).
- The tenant's server is on the critical path when validation is active; its availability affects its sales (document clearly).
- New tables: `validation_endpoints` (plan 7.4) and `validation_calls` (plan 7.6).
- Residual risk: seconds pass between approval and charge confirmation. A merchant that needs a stock guarantee must reserve it on approval and release it if `payment.succeeded` does not arrive in reasonable time.
- Full detail in plan section 15.8.
