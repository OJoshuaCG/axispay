# ADR-0069: One webhook endpoint per tenant and mode that also answers the pre-payment validation

- **Status:** Accepted (by the project owner, 2026-10-28). Supersedes the "separate from webhooks" part of [ADR-0024](0024-pre-payment-validation.md); amends [ADR-0057](0057-outgoing-webhooks-delivery-phase-5.md), [ADR-0058](0058-pre-payment-validation-phase-5.md) and [ADR-0008](0008-outgoing-webhooks-standard-webhooks.md). Extends [ADR-0067](0067-phase-5-gap-closure.md) (the validation metrics move with the endpoint).
- **Date:** 2026-10-28
- **Source:** plan 7.4, 7.6, 15.1 and 15.8; the owner's request to standardize the webhook concept.

## Context

The panel had two destinations that the merchant configured separately: "Webhook endpoints" (asynchronous events) and "Pre-payment validation" (a synchronous callback). Both are a signed HTTPS call to a URL of the merchant, but they were two entities, with two screens, two secrets and two sets of rules, and merchants read them as two different things. Nothing is in production, so the model can still change.

## Decision

1. **One entity.** A webhook endpoint is the only destination a merchant configures. The `validation_endpoints` table disappears; its settings live in `webhook_endpoints`.
2. **Event picker.** The merchant chooses, with checkboxes, which events the endpoint receives. `payment.pre_validation` is one more value of `enabled_events`, next to `payment.succeeded`, `payment.failed`, and so on. `*` means every asynchronous event and **never** includes `payment.pre_validation`: it blocks charges, so it is always chosen explicitly.
3. **Two delivery modes, one body shape.** Every body carries `type`. The new field `delivery_mode` (`async` or `sync`) tells the receiver whether an answer is expected: `payment.pre_validation` is `sync` (the payer is waiting; the receiver must answer `approve` or `reject`); every other event is `async` (any 2xx is enough). The pre-validation body keeps its `val_` id, and its content is unchanged.
4. **One secret.** The endpoint has one signing secret (and the previous one for 24 hours after a rotation) for both modes. Rotating it affects both.
5. **At most one endpoint per tenant and mode today, by configuration, not by schema.** `axispay.webhooks.max_endpoints_per_mode` is now 1 by default (it was 5). The table, the actions and the panel are written for several endpoints; raising the limit (or adding a per-tenant override) is the only change needed to offer more. Whatever the limit, **at most one endpoint per tenant and mode may subscribe to `payment.pre_validation`**: the database enforces it with a nullable unique column (`validator_slot`), kept in sync by the model.
6. **Validation settings on the endpoint**, shown only when `payment.pre_validation` is chosen: `failure_policy` (`fail_closed` by default, or `fail_open`) and `enabled_by_default` (whether new links ask for validation when the API says nothing). The failure counters and the alert of ADR-0058 move with them (`validation_consecutive_failures`, `validation_last_failure_at`, `validation_last_success_at`, `validation_last_alerted_at`).
7. **Health stays separate.** Only asynchronous deliveries count towards the 5-day auto-disable of ADR-0057, and a synchronous validation failure never disables the endpoint (it keeps ADR-0058's alert after 10 failures in a row). When an endpoint is disabled by failures or by the merchant, the validation keeps working if the endpoint subscribes to it: pausing events must not stop charges that were promised a validation. A merchant who pauses the endpoint therefore cannot pause its validation without first unsubscribing from it.
8. **Guard instead of fail-closed surprise.** An endpoint that subscribes to `payment.pre_validation` cannot be deleted, and the event cannot be unsubscribed, while the mode has open links that ask for validation (`validation_in_use`). The merchant cancels or lets those links finish first. This replaces ADR-0058's "remove at any time, links stop charging" rule: the platform refuses to create that situation instead of warning about it. The links created later without validation are not affected.
9. **Test button.** One "Send test" action takes the event type: `ping` (async) or `payment.pre_validation` (sync, `test: true`, never counts towards the alert, logged as a test).
10. **Logs.** `webhook_events` and `webhook_deliveries` keep the asynchronous history. `validation_calls` stays as the log of synchronous calls (its payload carries the payer's e-mail and name, so it stays encrypted and is deleted after 30 days); it now points to `webhook_endpoint_id`.
11. **The two HTTP clients stay separate on purpose.** They differ in what matters (timeout, retry only on a refused connection, 4 KB JSON answer, error kinds). They already share the signer and the SSRF guard. Merging the transport would put risk on the path that blocks charges for no gain to the merchant.
12. **Public contract kept.** `pre_payment_validation` on the link, the `pre_validation` block of the payment and the error `validation_endpoint_not_configured` keep their names (pbx-payments integrates with them). The error now means "no endpoint of this mode subscribes to `payment.pre_validation`".
13. **Permissions and security rules are unchanged.** `webhooks:manage`, re-authentication, the SSRF protection, e-mails to owners and the audit log (host only) apply to the single endpoint. The audit actions of the validation endpoint are folded into the webhook endpoint's.

## Consequences

- One screen, one URL, one secret: the merchant configures one thing, and the documentation talks about one thing.
- Migrations are rewritten (no data to migrate); the two Phase 5 migrations that created `validation_endpoints` and the endpoint columns change.
- The timeout of a synchronous call is still the global `AXISPAY_VALIDATION_TIMEOUT_SECONDS` ([ADR-0061](0061-validation-timeout-single-setting.md)); the asynchronous one stays at 10 s.
- A merchant that today receives the validation at a URL of its own must put that URL in the single endpoint and subscribe to `payment.pre_validation`.
- Offering several endpoints later needs only the limit, and a rule for which endpoint the merchant wants as the validator (already one per mode by the unique column).

## Alternatives considered

- **Keeping two entities and renaming the screens.** It does not remove the confusion the owner raised.
- **A separate "validator" flag instead of an event.** It would make the validation a second kind of thing again; as an event it follows the same picker as every other event.
- **Allowing `*` to include the validation.** A merchant that subscribed "to everything" would block its own charges whenever its server is slow.
- **One shared HTTP transport.** See decision 11.

## Unverified

- Written together with the code; the tests of this change are listed in the CHANGELOG entry and were run only through the authorized targeted runs.
