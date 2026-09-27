# ADR-0050: Linear payment flow: authorize, validate with the merchant, then capture

- **Status:** Accepted (by the project owner, 2026-09-27). Amends [ADR-024](0024-pre-payment-validation.md) (moment of the pre-payment validation).
- **Date:** 2026-09-27
- **Source:** plan ADR-024, sections 11.4, 15 and 15.8; Phase 4 and Phase 5 in section 27.

## Context

ADR-024 placed the merchant's pre-payment validation **before** the card is touched: the platform asked the merchant first and then created and confirmed the charge in one step. The plan itself records the weak spot: an approval does not guarantee the charge, because the card can still be declined or abandoned at 3D Secure, so a merchant that reserved stock on approval may reserve it for nothing.

The project owner wants a linear flow in which the merchant is only asked once the card is known to be good, and in which both merchant callbacks are optional.

## Options considered

1. **Keep ADR-024 as is:** validate first, then charge in one step. Simple, but the merchant is consulted for cards that may fail.
2. **Authorize, validate, capture (chosen):** the card is authorized first, the merchant decides, and the money is only taken after an approval.
3. **Charge first, refund on rejection:** takes the money and gives it back. Rejected: refunds cost fees, take days to reach the payer and look like a real charge on the statement.

## Decision

The payment of a link follows this order. Each step only runs if the previous one succeeded.

| Step | What happens | If it fails |
|---|---|---|
| 1. Card details | The payer enters the card on the payment page; Stripe tokenizes it in the browser (card data never reaches the platform). | The payer corrects the card. |
| 2. Checks | Payer fields, rate limits and bot protection pass (plan section 11.7), and currency confirmation if it applies (Phase 6). | The payer sees the corresponding message. |
| 3. Authorization | The card is **authorized, not charged**: Stripe confirms funds and completes 3D Secure if the bank asks for it. | The link stays payable; the payer can try another card. |
| 4. Pre-payment validation (optional) | If the merchant enabled it for the link, the platform sends the signed synchronous callback of section 15.8 and waits at most 5 seconds. | See below. |
| 5. Capture | The authorized amount is captured. This is the charge; the link becomes paid once Stripe confirms it. | See below. |
| 6. Notification (optional) | If the merchant configured webhooks, `payment.succeeded` is delivered through the outbox with retries (section 15). Its delivery result never affects the charge. | Retried; visible to the merchant in the panel. |

Rules that do not change from ADR-024 and section 15.8:

- **Both callbacks are optional.** A merchant with no validation URL and no webhooks is valid: the flow goes from authorization straight to capture.
- **The merchant answers with a decision, not just a status code:** HTTP 200 with `approve` or `reject`. `reject` may carry a message for the payer and may cancel the link.
- **Failure policy per merchant:** on timeout, error or an invalid answer, `fail_closed` (default) does not charge and `fail_open` charges and records it.
- The validation never runs while a row lock or a transaction is held, and the link's state is checked again after it (rules.md, rule 7b).

New rules:

- **Reject or `fail_closed` voids the authorization.** No money is taken and no refund is needed. The payer's bank may show the authorization as pending for a few days until it expires or is released; the payment page says so in plain words.
- **No automatic refund when the final webhook fails.** Delivery failures are usually temporary and are retried for about 27 hours. The merchant sees failed deliveries in the panel and can refund a payment from the panel or the API (Phase 7).
- **Authorization and capture happen in the same payer session**, seconds apart. An authorization that is not captured (for example, the process stops between steps 3 and 5) is voided by the reconciliation job; it never stays open until Stripe's own expiry.
- The only payment method of the MVP is the card (ADR-018), and cards support separate authorization and capture.

## Incoming Stripe webhooks stay mandatory

Decided by the project owner on 2026-09-27. The linear flow does not make the webhooks that Stripe sends to the platform optional. The platform's database is fed by those events and corrected by reconciliation ([ADR-017](0017-own-db-source-of-truth.md)). The payer's session only covers what happens while the payer is on the page. Many changes happen later, or outside that session:

| Situation | Why only a Stripe event tells the platform in time |
|---|---|
| Dispute or chargeback | It arrives days or weeks after the payment, long after the payer left. |
| Refund made from the Stripe Dashboard | The merchant acts outside the platform; nothing in the platform starts it. |
| 3D Secure approved but the payer closed the tab | The authorization exists, but the payer's session never comes back to report it. |
| Network cut during capture | The platform does not know whether the capture happened; Stripe's event settles it. |
| Changes to the connected account | Charges disabled, requirements due or the account deauthorized: the platform must stop offering payments at once. |

Reconciliation remains a **safety net only**. It runs minutes or hours later, so it catches what was missed, but it cannot replace the events. A deployment that receives no events is misconfigured and is reported as such (see Consequences).

Who configures what:

- **Tenants configure nothing.**
- **Create or connect with Stripe** (`platform_onboarding`, later `oauth`): the platform operator creates **one** Connect webhook destination per mode (test and live) in the platform's Stripe Dashboard, with the pinned API version and the list of events the platform uses. This is a required deployment step.
- **Connect with API keys** (`api_key`): AxisPay creates the webhook endpoint on the merchant's own Stripe account when the connection is made, and removes it when the connection ends ([ADR-0047](0047-stripe-connection-phase-2-and-api-key-reordering.md)).

## Rationale

The merchant is only consulted when the payment can actually succeed, which removes the plan's own residual risk of reserving stock for failing cards. A rejection voids an authorization instead of refunding a charge, which is cheaper and clearer for the payer. The flow stays linear and easy to explain to integrators.

## Consequences

- **Phase 4 (checkout)** creates payment intents with separate capture and implements steps 1, 2, 3 and 5, with the exact point where step 4 plugs in. Its technical spike must also verify, in Stripe test mode: separate capture with direct charges on Mexican connected accounts and with the `api_key` method, how voided authorizations appear and whether they cost anything, and the authorization lifetime for cards.
- **Phase 5** connects step 4 (pre-payment validation) and step 6 (outgoing webhooks).
- The payment attempt states of section 9.2 gain an "authorized, waiting for capture" stage; the link stays `processing` during it, so no second attempt can start.
- Integrator documentation must say: approve means "go ahead and charge"; confirm the sale only on `payment.succeeded`.
- If the spike shows that separate capture is not available for a connection method or country, stop and report before building on it (plan section 27, Phase 4).
- The deployment guides make the Connect webhook destination a required step, with a checklist per mode. The platform's diagnostics report an error when a mode is in use without its webhook signing secret, and a warning when connections that can charge receive no event for 7 days (configurable). The platform panel shows the last Stripe event of each connection and flags the silent ones.
