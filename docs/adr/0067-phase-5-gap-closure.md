# ADR-0067: Phase 5 gap closure: link lifecycle events, delivery log retention and validation metrics

- **Status:** Proposed; it needs the owner's acceptance. Extends [ADR-0057](0057-outgoing-webhooks-delivery-phase-5.md) (event catalog, delivery log) and [ADR-0058](0058-pre-payment-validation-phase-5.md) (pre-payment validation).
- **Date:** 2026-10-21
- **Source:** plan 15.2, 15.6, 15.8.7 and 26.2 (critical case 24); the Phase 5 code review of 2026-10-21.

## Context

A review of Phase 5 against the plan found four gaps: `payment_link.created`, `payment_link.expired` and `payment_link.canceled` were in the catalog but never sent; the delivery log (`webhook_deliveries`) grew forever although plan 15.6 keeps it 30 days; the per-tenant validation metrics of plan 15.8.7 (rejection rate, failure rate, p95 latency) did not exist; and critical case 24 (the validation call holds no lock, the state is re-verified after it) was covered only by the "no open transaction" guard.

## Decision

1. **Link lifecycle events.** `payment_link.created` is recorded by `CreatePaymentLink` in the transaction that creates the link (an idempotent replay records nothing). `payment_link.expired` and `payment_link.canceled` are recorded by `PaymentLinkStateMachine::expire()` and `cancel()`, in the caller's transaction, because every path that closes a link goes through them: the expiry job, the API and panel cancel, a merchant rejection with `cancel_link`, a disconnected gateway and a closed tenant. A link that was already closed records nothing. `data.object` is the link (`PaymentLinkPresenter`), with `cancel_reason` (`rejected_by_merchant`, `gateway_disconnected`, `tenant_closed` or the merchant's free text). `PaymentLinkClosed` (dispatched after commit) is not used for the outbox, which must be in the same transaction (rules.md rule 7).
2. **Delivery log retention.** `axispay:webhook-deliveries:purge` runs daily at 04:00 (one server, no overlap) and deletes, across tenants and in chunks of 1000, the delivery attempts older than `axispay.webhooks.delivery_retention_days` (30). A `pending` attempt is never deleted, whatever its age. The outbox (`webhook_events`) is kept. A new index on `webhook_deliveries.created_at` serves the purge. `Webhooks\Services\WebhookDeliveryRetention` joins the scope-bypass whitelist (ADR-0031): it deletes by age and status only and never reads a row.
3. **Validation metrics.** The "Pre-payment validation" page shows, for the current tenant and mode, over the calls' retention window (30 days): the share of calls rejected by the merchant, the share that failed (timeout, connection, invalid answer...) and the p95 latency (nearest rank). Test calls and calls still waiting for an answer are left out. Rates are computed with integer arithmetic (one decimal, half up). The alerts of section 24 stay out of scope.
4. **Case 24 test.** A concurrency test (committed rows, a second connection) runs a checkout whose merchant answers slowly: during the call the second connection locks the link and its attempt with `FOR UPDATE NOWAIT` and closes the tenant. The locks succeed at once, and the approved payment is voided, not captured, because the state is re-verified after the call.

## Consequences

- Endpoints subscribed to every event start receiving the three link events; endpoints with an explicit list receive them only after adding them.
- Delivery attempts older than 30 days disappear from the endpoint's log, as the guide already said.
- One migration (an index) and one config key; no new table or column.

## Alternatives considered

- **Recording the close events from each caller** (job, cancel action, checkout). Several places to keep in sync; the state machine is the one place every close passes through.
- **Deleting the outbox together with its deliveries.** `GET /v1/events` has its own 30-day window (ADR-0060); the outbox is left to that decision.
- **Computing p95 with `PERCENTILE_CONT`.** MariaDB supports it only as a window function; an ordered `OFFSET` read is simpler and portable.

## Unverified

- The new and changed tests were written but not run when this ADR was drafted (Pint and Larastan pass on the changed modules).
