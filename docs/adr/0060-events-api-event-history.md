# ADR-0060: Event history in the public API (`GET /v1/events`)

- **Status:** Proposed (Phase 5)
- **Date:** 2026-10-02
- **Source:** master plan sections 10.1, 10.2, 10.8, 15.3 and 27 (Phase 5); [ADR-0057](0057-outgoing-webhooks-delivery-phase-5.md) (events are stored whether or not an endpoint exists), [ADR-0048](0048-payment-links-api-phase-3.md) (API conventions, rate limit, scopes).

## Context

Section 10.8 of the plan says integrators can read the event history: one event by ID and a filtered list, with the scope `events:read`, returning the same body that was or will be sent by webhook, kept 30 days. It is also the base of the "fetch-back" pattern (confirm what a webhook says before releasing goods). Since ADR-0057 every business event is stored when it happens, even when no endpoint is subscribed, so the history is complete. The plan leaves a few details open; they are decided here.

## Decision

1. **Two read-only endpoints, one scope.** The list and the retrieval of one event both need the `events:read` scope; a key without it gets `403 insufficient_scope`. They follow the rest of the API: authenticated with the API key, counted in the key's rate limit with the `RateLimit-*` headers, errors in the common format, and the key's account and mode decide what can be seen. An event of another account or mode, an unknown ID and an ID with another prefix are all `404 resource_not_found`.
2. **The event is the webhook body, byte for byte.** The stored, frozen body is returned as it is, not rebuilt, so what the integrator reads equals what was signed and sent, including later retries and resends. In the list, each element is that same body inside the usual list envelope (`object`, `data`, `has_more`).
3. **Same pagination as the other lists.** Newest first, `limit` 1 to 100 (20 by default), `starting_after` for older events, `ending_before` for newer ones (not both), with event IDs (`evt_…`) as cursors. A malformed limit or cursor is `400 parameter_invalid` naming the parameter.
4. **Filters:** `type` (one event type of the public catalog) and `created[gte]` / `created[lte]` (Unix seconds or ISO-8601 with a time zone, as in the payment links list). An unknown type is refused instead of returning an empty list, so a typo is noticed. `created` refers to the moment the event was stored; for an event published late by the sweeper this can be a little later than the `created_at` inside the body.
5. **The test event is not history.** The `ping` event sent from the panel's test button is not a business event: it is not listed, cannot be retrieved and cannot be used as a type filter.
6. **Retention is a reading window of 30 days.** Only events stored in the last 30 days (configurable) can be listed or retrieved, so the promise of the plan holds from the first day. Deleting older rows is not part of this decision (the same housekeeping is still pending for the delivery log, see ADR-0057).
7. **No Stripe identifiers.** The history only returns bodies built for webhooks, which already use our identifiers.
8. **Payments are not yet an endpoint.** `GET /v1/payments` arrives in Phase 7; until then the events (`payment.succeeded`, `payment.failed`, `payment_link.paid`) are the way to read a payment, including its `pre_validation` block.

## Consequences

- Integrators can reconcile after downtime by listing events since the last one they processed, and confirm a webhook by retrieving its event, within 30 days.
- A key created for reading events only needs `events:read`; existing keys keep working and gain access only if the scope is added to them.
- Reading never changes anything: no event, delivery or endpoint state is touched.
- Rows older than 30 days stay stored until a housekeeping job is added; they are simply invisible to the API.
- The API contract (`docs/api/openapi.yaml`) documents both endpoints, the event body and the outgoing webhooks.
