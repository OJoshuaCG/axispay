# ADR-0062: `payment.canceled` for every void, richer payment events and `GET /v1/payments`

- **Status:** Proposed. Requested by the integration with pbx-payments (2026-10-06), whose callback credits a balance before it approves and must undo it when the authorization is released; it needs the owner's acceptance. Extends [ADR-0057](0057-outgoing-webhooks-delivery-phase-5.md) (event catalog), [ADR-0058](0058-pre-payment-validation-phase-5.md) and [ADR-0060](0060-events-api-event-history.md) (which left payments to Phase 7).
- **Date:** 2026-10-06
- **Source:** plan 10.6 and 15.2; [ADR-0050](0050-linear-payment-flow-authorize-validate-capture.md), [ADR-0051](0051-checkout-and-card-payments-phase-4.md); the `cirox-payment-links` change (pbx-payments side: spec B2 and B3, tasks CRX-3, CRX-4, CRX-5).

## Context

An integrator that does something when it approves the pre-payment validation (reserves stock, credits a balance) needs to know when the authorization is later released instead of captured. Until now it could not: a void caused by a capture window that elapsed, a closed link, a refused capture, a merchant rejection or `fail_closed` sent no event at all, and `payment.failed` is only for declined cards. The events also did not carry the link's `client_reference_id` nor the capture time, and there was no way to read a payment back (the `payment` of a link is always `null`, and `GET /v1/payments` was left to Phase 7).

## Decision

1. **New event `payment.canceled`**, subscribable like the others. `data.object` is the payment, `data.reason` is a `VoidReason`: `merchant_rejected`, `validation_failed`, `capture_window_elapsed`, `link_closed`, `abandoned_action`, plus the new `gateway_canceled` for a release we did not ask for (an authorization that expired at the gateway, a refused capture, a cancellation reported by its webhook). The payment keeps its `pay_` ID, the same as in the validation call of that attempt.
2. **Recorded in `ApplyProviderPayment`**, in the transaction that applies the cancellation, not in `VoidAuthorization`. That is the single place a gateway state is applied, so every path (checkout, webhook, reconciliation, void) records it, exactly once: the attempt is terminal afterwards, so a later webhook about the same cancellation changes nothing. `VoidAuthorization` only passes its reason.
3. **Only for payments that had reached the payer's bank**: a payment that was authorized (`requires_capture`), asked for a 3D Secure step (`requires_action`) or was `processing`. A card form that was never confirmed, and an attempt that never reached the gateway (closed locally), send nothing: no money was ever held.
4. **The payment of every payment event carries three more keys**: `client_reference_id` (the link's, null when none), `captured_at` (when it became `succeeded`, null until then) and `fx` (null until the tenant FX applies; the key is part of the contract from now on). Nothing else is added: no gateway identifier, no card data (ADR-019, ADR-0058).
5. **`GET /v1/payments/{id}` and `GET /v1/payments`**, scope `payments:read`, read only. The object is the event's payment (`PaymentSnapshot`) plus `card` (`brand` and `country` only), `authorized_at` and `canceled_at`; never the last digits, a fingerprint, the payer or a gateway ID. The list is newest first with the same cursor pagination as the other lists (`pay_` cursors), filters `status`, `payment_link` (the way to read the payments of a link) and `created[gte|lte]`. Another account's or mode's payment is `404`. The `payment` field of a link stays `null`.

## Consequences

- Endpoints that subscribed to an explicit list of events do not receive `payment.canceled` until they add it; endpoints subscribed to every event do. The new keys of the payment object are compatible additions (integrators already ignore unknown fields).
- When the reason is not known (the gateway cancelled first and we only saw its report) the reason is `gateway_canceled`, never a guess; the audit log still records our own void reasons.
- `GET /v1/payments` now answers what ADR-0060 point 8 deferred; refunds, disputes, last digits and payer data stay for Phase 7 and need their own decision.
