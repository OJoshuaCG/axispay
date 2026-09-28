# ADR-0051: Checkout and card payments (Phase 4), built without Stripe keys behind an acceptance gate

- **Status:** Accepted (owner decisions of 2026-09-27). **Phase 4 acceptance is conditional** on the Stripe acceptance gate (section 12).
- **Date:** 2026-09-27
- **Source:** master plan sections 6.3, 7.5, 9, 11, 12, 14, 19, 23, 26.2 (cases 1, 2, 3, 4, 16, 17, 20) and 27 (Phase 4); [ADR-005](0005-own-domain-payment-element.md), [ADR-006](0006-single-use-reopenable-links.md), [ADR-017](0017-own-db-source-of-truth.md), [ADR-018](0018-card-only-mvp.md), [ADR-019](0019-gateway-port-adapter.md), [ADR-0038](0038-platform-branding-powered-by.md), [ADR-0047](0047-stripe-connection-phase-2-and-api-key-reordering.md), [ADR-0049](0049-panel-money-formatting-and-tenant-timezone.md), [ADR-0050](0050-linear-payment-flow-authorize-validate-capture.md); the approved checkout design spec with its Phase 4 amendments, [docs/frontend/checkout-design.md](../frontend/checkout-design.md).
- **Technical detail** (how the code keeps these rules: locks, keys, retries, time limits, headers): [docs/architecture.md, "Payment mechanics"](../architecture.md#payment-mechanics-technical-detail-of-adr-0051). Deployment steps: [docs/deployment/dokploy.md, "Upgrading to Phase 4"](../deployment/dokploy.md#upgrading-to-phase-4).

## 1. Context and owner decisions

Phase 4 delivers the public payment page, payment attempts, 3D Secure, the completion page, the handling of Stripe's payment events, reconciliation, card-testing protection, the page's security and the recording of openings. The plan asks for a technical spike with Stripe test mode first. The platform does not have Stripe test keys yet, and the owner does not want to wait for them.

Owner decisions (2026-09-27):

1. **Build without Stripe keys.** The Stripe side is written from Stripe's current documentation and exercised with Stripe's answers simulated. The plan's spike becomes an **acceptance gate** (section 12): the checks against real Stripe test mode must pass before Phase 4 is accepted.
2. **A checkout sandbox for local development and tests only** (section 11), so the whole payment page can be used and tested end to end without keys. It can never run in production.
3. **Payer-facing Spanish uses "tú".** The panels keep "usted".
4. **The page allows every Stripe.js origin** Stripe's security guide lists, including its subdomains.

## 2. What the payer sees

- **One page per link**, on the pay host, at the link's address. Paying, continuing after 3D Secure, the completion page (where the payer lands after paying) and the status the completion page polls all hang from that address. An invalid address (malformed, unknown, another case) always answers the same "link not found" page, without merchant information.
- **States** (plan 11.2): active; processing (checked every 3 seconds for 2 minutes, then "check again later"); paid (only the session that paid sees "Payment complete" with the amount; anyone else sees "This payment has already been made"); expired; canceled; and "not accepting payments" (a long card-testing block, or no gateway that can charge). An active link past its expiry is expired when the page opens. The informative states show the description and date, never the amount; the amount appears only while the link can be paid, during a payment and to the session that paid. No state repeats its heading in its text.
- **Closed tenants** (plan 21.3): a closed tenant's links stop taking payments at once, and closing the tenant cancels its active links in both modes (a link with a payment under way is left to finish and handled on a later run). A suspended tenant keeps collecting (ADR-013).
- **Language:** the link's language, unless the payer chose another with the language switcher. Every answer about a link speaks it, including "too many requests". The page never shows framework text: an expired session asks the payer to reload the page; not found, too many requests, server errors and maintenance have the checkout's own pages with the platform footer. Waits are written with correct singular and plural forms ("1 minute", "30 minutes").
- **Session:** the pay host has its own anonymous session, never shared with the panels, which protects the payment requests and remembers two things per link: the payer's own declines (for the security check) and whether the payer paid in this session. No payer data is kept in it. Checking the status uses no session.
- **Form** (the design spec and its amendments): payer fields, then the payment alerts, then the card form, the security check when required, and Pay. The phone's country shows "country (+code)" in full. The space reserved for the card form while it loads matches what appears and disappears once it is there; if the card form cannot load, the page says so and Pay stays disabled. The page follows the device's light or dark setting (no theme toggle); a solved security check is kept when it switches. The card form uses the page's typeface with two weights, 400 and 500.
- **The page never leaves the payer stuck:** if anything fails while paying (network, Stripe, an unexpected answer), Pay never stays busy; the payer sees the generic error and may try again. **If Stripe is slow, the page switches to "your payment is processing" instead of failing** whenever a charge may be under way. Every call a payer's request makes to Stripe (reading the card, creating and confirming the payment, the merchant's validation, the capture or the void, and their repetitions) starts only if it can finish before the page must answer; what does not fit is completed in the background a minute later (captured within the capture window, voided past it), and Stripe's events and the reconciliation remain the safety net. Each status check of the completion page has its own time limit. Background work stops in time too: it never calls Stripe past its own time limit or while it may have lost hold of the payment, and whatever it could not finish is left for the next check.
- **Declines** are always shown with one generic message; the decline's reason stays in the tenant panel (plan 11.7 rule 7).
- **Stripe's card form** is initialized for the tenant's connection: the platform's key and the tenant's account for the connection methods through the platform, the merchant's own publishable key for API keys (plan 12.4.1, case 20). No secret key ever reaches the page.

## 3. The payment flow

The linear flow of ADR-0050: authorize, ask the merchant, then capture.

1. The link must be payable (active, not past its expiry, not blocked).
2. Payer fields are checked against the configuration frozen on the link (plan 19.1).
3. Card-testing protection (section 6), with the security check verified on the server before Stripe is called.
4. The card is read (country, brand, last four digits).
5. The amount to charge is decided in one place, which Phase 6 extends with currency conversion; in Phase 4 it is always the link's amount.
6. The link's single payment is claimed (section 4) and the payer's data stored encrypted.
7. The Stripe payment is created (card only, **manual capture**, our identifiers only, no platform fee) and then confirmed with the payer's card. Creating and confirming are always two separate steps: a lost answer while creating never charged anyone.
8. **3D Secure** runs in the page; the bank's answer comes back to the same session, which is the only one allowed to continue that payment, once. Anyone else is told a payment is in progress.
9. **Authorized:** the merchant's pre-payment validation is asked (Phase 4 has none configured: approved), and the decision is kept on the payment at once, so it is never asked twice and a rejected authorization is never captured. Approved → captured; rejected → **the authorization is voided**: nothing is charged, and the payer sees the merchant's message and "you were not charged". If the link closed meanwhile (or its tenant), the authorization is voided instead, unless Stripe says the payment already succeeded, in which case the payment wins.
10. **Capture window:** an authorization is captured only within **15 minutes of being authorized** (configurable). Past it, whoever sees it next voids it without asking the merchant; a payment Stripe already reports as succeeded wins. The window stays well below the card hold Stripe keeps (7 days for most cards), so an authorization is never left to expire on its own.
11. If the capture or the void cannot be done right now (Stripe unreachable or slow, or no time left in the payer's request), the payer sees "your payment is processing", never an error page and never "no charge was made"; a background completion a minute later, Stripe's events or the reconciliation complete it with the kept decision.
12. The database follows what Stripe reports; Stripe's events remain the source of truth (ADR-017, ADR-0050).

**A payer is never charged twice:** a lost answer, a retry, a second tab or a crash can repeat a request, but Stripe recognizes the repetition and the platform continues from the payment's real state. Two payers of different links never block each other; a rare database conflict is retried and, if it persists, the payer is told a payment is in progress.

## 4. One payment per link

- **At most one payment under way per link.** A decline does not start a new one: the same payment is tried again with another card, and every decline is recorded once. The payment keeps the connection and the Stripe account it was created with.
- **While a payment is being confirmed, the link is reserved:** it can neither expire nor be canceled, and a second tab or device is told a payment is in progress instead of paying again (plan 26.2 case 1). When the confirmation ends without a payment under way (a decline, an error, a refusal), the link becomes payable again, or expired if its expiry passed meanwhile. A reservation left by a confirmation that died (a crash) is taken over by the next payer.
- **A late success wins** (ADR-006): a payment that succeeds on an expired or canceled link marks the link paid; the payment is flagged as late (kept for the Phase 5 webhook), and the anomaly is audited and logged.
- **A closed payment that Stripe later reports as succeeded** (for example a void that lost a race with a capture) is not reopened: it is flagged **needs review**, audited once and logged at critical level, since money may be unaccounted for.
- **Old or out-of-order reports never undo progress:** a late event or a slow check that says a payment went back to "waiting for a card" is ignored unless a new decline explains it.
- When a link expires or is canceled, its payment still waiting for a card is closed and its Stripe payment canceled; if Stripe says it had already succeeded, the payment wins.

## 5. Stripe events and reconciliation

**Events** (plan 14):

- Six payment events are handled (authorized, succeeded, failed, processing, bank verification required, canceled). The platform never trusts an event's content: it reads the payment from Stripe again and applies its current state, so duplicates (case 3) and out-of-order events (case 4) are harmless.
- An authorization reported by an event is completed (validation, capture): a payer who closed the tab after 3D Secure still pays, once.
- **Payments the platform did not create** (frequent on accounts connected with API keys, which carry the merchant's other sales) are ignored before any call to Stripe (plan 14.4).
- **Stored events carry no payer data:** payment events keep only the envelope, and every stored event is encrypted.
- **Each event is processed once at a time:** while an event is queued or waiting for its next try, a duplicate delivery, the automatic recovery or an operator retry does not queue it again.
- **Recovery:** an event stuck unprocessed for five minutes is queued again automatically; an event whose account had no connection is kept and routed as soon as the connection exists; a failed event raises an alert-level log line and an audit entry, and the operator can retry one event or every failed event (the deployment guide explains how). Retries are audited and never apply an event twice.
- **Errors a retry cannot fix fail at once** (Stripe refusing the credentials or the request) instead of being retried. Refused credentials of an API-keys connection mark it "invalid credentials" (only if its keys did not change meanwhile, plan 12.6) and notify the tenant.
- **Routing:** a payment event goes to the connection that created the payment; other events to the account's current connection, never to another tenant's old connection. **One account, one method:** an account the platform reaches through Stripe Connect cannot also be connected with API keys, so an event never arrives by two routes.
- **Adopting a payment after a crash** (created at Stripe, but the platform never stored its reference) requires that Stripe created it while this payment could have: from a minute before until a day after. Residual risk: someone with access to the merchant's own Stripe account could create a payment for the same amount naming our payment inside that window; it would be adopted.
- **Refunds and disputes are not subscribed in Phase 4.** Stripe does not resend events from before a subscription, so refunds and disputes made in the Stripe Dashboard between the Phase 4 go-live and Phase 7 will never arrive as events: Phase 7 must fetch them from Stripe, from the Phase 4 go-live date, before relying on events.

**Reconciliation** (plan 12.5, every 15 minutes, per tenant and mode):

- Payments under way at Stripe (bank verification, authorized, processing) unchanged for 10 minutes are read again and applied like events. Payments still waiting for a card are not visited: they cost nothing and close with their link. Each run takes the least recently visited first, a bounded number and for a bounded time, so failing payments can never starve new ones.
- A bank verification left unanswered for 30 minutes is canceled (the link becomes payable again or expires); an authorization past the capture window is voided; a link left reserved with no payment under way is freed or expired.
- A payment closed without Stripe because its connection lost its keys is flagged **needs review**: a hold may remain on the payer's card until the bank releases it, and the merchant should check in Stripe.
- The daily comparison of the last 48 hours (plan 12.5) is left for later; it needs listing payments per connection, an item of the acceptance gate.

## 6. Card-testing protection (plan 11.7)

| Rule | Value |
|---|---|
| Per link | 5 confirmations in 15 minutes, then 30 minutes paused |
| Per client IP | 10 confirmations per hour, across links (IPv6: the whole /64 network) |
| Unrecognized cards | 5 per link and client in 15 minutes; also 20 per client network per hour, across links |
| Security check (Turnstile) | Required from the first payment try after a decline on the link or in the payer's session (below) |
| Long block | 10 declines on a link within 24 hours block it for 24 hours (a refinement of plan 11.7 rule 4, which sets no time window); the count restarts when the merchant lifts a block |
| Notification | E-mail to the tenant's owners and every user who may cancel links (section 9) |
| Unblock | Tenant panel, link detail, by users who may cancel links, with re-authentication; audited |
| Pay-host request limits | Per client and link: the page and the completion page 60 per minute each, status checks 90 per minute, Pay and the 3D Secure continuation 30 per minute each |
| Superadmin alerts | Phase 9; until then an alert-level log line |

- **Only real confirmations count**, the moment they are about to reach Stripe, and a burst of simultaneous requests can never exceed the limit. Answers of "a payment is in progress" never count, so tabs or a double click cannot pause a link without declines. Cards Stripe does not recognize, or cannot read because it is unavailable, count only against that client, so a stranger can pause only themselves.
- **The bot check can be switched off temporarily**, until a Cloudflare account exists: [ADR-0052](0052-temporary-turnstile-switch.md). Every other limit in this section stays.
- **Turnstile threshold:** Turnstile is required from the first payment try after a decline on the link or in the payer's session. Plan 11.7 rule 3 ("from the 2nd failed attempt") and critical case 16 ("after a decline") disagree; the stricter reading was chosen, configurable, pending the owner's confirmation.
- **The security check is verified on the server** before Stripe is called and fails closed; it must have been solved on the pay host for a payment. A solved check is valid once: after any payment try the page asks for a new one. Production refuses to start without the security-check keys (without them a link would stop taking payments after its first decline).
- **A card-testing pause tells the browser the real wait**, so Pay never comes back early; other request limits say "too many requests, try again in N seconds".
- **Real client addresses** require the reverse proxy to be trusted: otherwise every payer would share the proxy's address and the per-IP limit would pause payments platform-wide. Production refuses to start without it.
- **Accepted limits:** superadmin alerts arrive with Phase 9. A stranger who knows a link can still pause it for everyone with real cards; that is inherent to any per-link limit, and the merchant can lift a long block.

## 7. Data kept and retention

- **Payer data** (plan 19): the page collects the whole MVP catalog as configured on the link (e-mail, name, phone with Mexico first, company, billing address, tax ID, notes). It is stored encrypted, apart from the financial records, with its mode, and never logged. **Retention: 24 months after the payer entered the data** (the plan counts from the payment; the difference is at most the minutes of one attempt). The per-tenant choice of 6, 12, 24 or 60 months arrives with Phase 8.
- **Privacy notice required** (plan 11.3, 19.2): a tenant without a privacy notice collects **no** payer data; the payment itself still works, the omission is logged and the panel warns on each affected link. When the merchant collects data, the page says the merchant receives it and links the merchant's notice. Deviation from plan 19.1: phone numbers are checked by structure (length, the Mexican 10-digit rule), and the optional check of e-mail domains is not done.
- **The payer's IP address and browser** are kept on the payment and each decline, for card-testing investigations (matching Stripe Radar and the web server's records); never shown to other tenants, never sent to Stripe by us, never logged. They are **cleared 90 days after the attempt closes, by the same daily purge that later clears payer data at 24 months (Phase 8). Until Phase 8 nothing is purged.** Hashing or truncating them was rejected: neither can be matched with Radar or an abuse report.
- **The card's fingerprint** (the same card gives the same value on one Stripe account) is kept for forensics; payers never see it and the panel shows only the last four digits.
- **The kind of a failed try** (card declined, insufficient funds, expired card, incorrect card details, authentication failed, processing error) is kept with Stripe's own codes; the platform decides with the kind, and the codes are shown only in the tenant panel.

## 8. Security of the page

- **The page only runs its own scripts and Stripe's and Cloudflare's**, can only be framed by nobody, and sends data only to itself and Stripe; this holds for every answer of the pay host, errors included.
- It is never cached, never indexed, sends no referrer (the link's address is secret) and forces HTTPS.
- Stripe's card form runs in Stripe's own frame: card numbers never reach the platform. The page's font files are served so Stripe's frame may use them.

## 9. Tenant panel, block e-mail and unblocking

- **The link detail shows its payments**, read-only, to users who may read payments: status, amount, card brand (by its commercial name) and last four digits, card country (by name, in the reader's language), declines with the last decline explained in words (Stripe's code next to it, for support), why a payment needs review (closed without Stripe, or paid after it was closed), whether it was paid late, the Stripe payment reference (support only), and dates. A separate payments list is left to Phase 7, with refunds.
- **Needs review** means the merchant should check the payment in Stripe: a card hold may remain (closed without Stripe), or money arrived for a closed payment (refund it if it should not have been charged).
- **The blocked-link e-mail** goes to the tenant's owners and to every user who may cancel links, in the tenant's language, names the mode once and links to the link's detail, where the block is lifted. The gateway connection e-mails are sent in the tenant's language too.
- **Lifting a block** is done from the link's detail by a user who may cancel links, after re-authenticating (password or 2FA code), like revoking an API key; it is audited, and declines before it no longer count.

## 10. What Phase 4 records for later phases

- **Business events for Phase 5's webhooks** (nothing is delivered in Phase 4): link opened (first opening, then at most once every 30 minutes, with the count; link previewers excluded), payment processing, payment failed (each decline, with the count), payment succeeded and link paid (with the late flag). They are recorded together with the change and **freeze what Phase 5 needs at that moment** (plan 15.3): the link as the public API shows it and a minimal payment (our IDs, status, amount in decimal and minor units, currency, late flag, decline count and a generic failure code; never Stripe's raw decline code, which can reveal fraud signals). Later changes never alter a recorded event.
- **The extension point for the merchant's pre-payment validation** (Phase 5): the complete void path is built and tested, so Phase 5 only plugs the callback in.
- **Phase 6** plugs currency conversion into the single place that decides the amount.
- **Phase 7** must fetch the refunds and disputes made in Stripe since the Phase 4 go-live (section 5).
- **Phase 8** must set a purge date for the payer's IP address and browser (90 days after the attempt closes) and run the payer-data purge.
- **Phase 9** adds the superadmin alerts for failed events and card testing.
- **The public API** does not change in Phase 4: a link's payment stays empty and there is no payments endpoint yet, because the payment object needs currency, payer and validation details that later phases fill.

## 11. Sandbox

- On only in local development and tests; **the application refuses to start** with it anywhere else, and it refuses live-mode links.
- A stand-in for Stripe on the server and in the browser: the card form becomes a scenario picker (approved, declined, insufficient funds, bank verification, slow processing) and 3D Secure a "sandbox bank" dialog. A demo command creates a demo tenant and links in every state (see the developer guide).

## 12. Stripe acceptance gate (conditional acceptance)

Phase 4 is accepted only when these are confirmed against real Stripe test mode, and this ADR is updated with the results. If any of 1, 3 or 12 fails, stop and report before building on it (plan 27, ADR-0050).

**A. Automated** (the Stripe contract tests, run for both the platform connection and the API-keys connection; the API-keys run uses a restricted key holding only the documented permissions):

| # | To verify | Why it matters |
|---|---|---|
| 1 | A payment prepared in the browser with the platform's key confirms a payment created on the merchant's account (direct charges) | The whole flow (ADR-005) |
| 2 | The card's country, brand and last four digits can be read before paying | Currency rule (Phase 6), panel |
| 3 | **Manual capture** on Mexican connected accounts and with API keys | ADR-0050 |
| 4 | Voiding an uncaptured authorization: the resulting state | Reject path, capture window |
| 8 | 3D Secure is asked for with a test card (server half) | 3D Secure |
| 9 | A decline comes back with the reference of the failed charge | One record per decline |
| 10 | A restricted key with only the documented permissions can create, confirm, capture and cancel payments and read cards | API-keys checkout |

**B. Checked by hand and recorded in this ADR:**

| # | To verify | Why it matters |
|---|---|---|
| 4 | Voiding an authorization: what the payer's statement shows and **whether it costs anything** (Stripe's pricing in Mexico; still unverified) | Reject path, capture window |
| 5 | How long an online card authorization lasts (Stripe documents 7 days) | Capture window, reconciliation |
| 6 | Changing the amount and currency in the card form before paying | Phase 6 conversion |
| 7 | The page's typeface inside Stripe's card frame | Design (fallback: the system font, never a font CDN) |
| 8 | 3D Secure in the browser on a connected account, and the return to the page | 3D Secure |
| 11 | Billing details sent with the card when the card form does not ask for them | Payer fields |
| 12 | Events of connected accounts delivered to the platform's Connect destination with the pinned API version, including the authorized event | Section 5 |

## 13. Consequences

- Payers can pay links with cards, including 3D Secure, with the authorize-validate-capture flow; tenants see payments and declines in the panel; the platform's records follow Stripe through events and reconciliation.
- Local development and the browser checks run the full flow in the sandbox; production can never enable it.
- Phase 5 plugs the merchant callback in and turns the recorded business events into webhooks; Phase 6 plugs conversion in; Phase 7 backfills refunds and disputes; Phase 8 adds the purges and branding; Phase 9 the superadmin alerts.
- Deploying Phase 4 needs a few one-time steps (the database update, the merchants' webhook endpoints, the six events on both Connect destinations, the security-check keys, the sandbox off, the trusted proxy): [docs/deployment/dokploy.md, "Upgrading to Phase 4"](../deployment/dokploy.md#upgrading-to-phase-4).

## Sources consulted (2026-09-27)

- Stripe: [Finalize payments on the server](https://docs.stripe.com/payments/finalize-payments-on-the-server?platform=web&type=payment); [Elements without an intent](https://docs.stripe.com/js/elements_object/create_without_intent); [Create the Payment Element](https://docs.stripe.com/js/elements_object/create_payment_element); [Create a PaymentIntent](https://docs.stripe.com/api/payment_intents/create); changelog entries [Adds the allowed payment method types parameter](https://docs.stripe.com/changelog/dahlia/2026-07-29/allowed-payment-method-types-parameter) and [Removes support for specifying payment method types](https://docs.stripe.com/changelog/dahlia/2026-08-26/removes-payment-method-types-parameter-from-payment-intents-setup-intents); [Idempotent requests](https://docs.stripe.com/api/idempotent_requests); [Place a hold on a payment method](https://docs.stripe.com/payments/place-a-hold-on-a-payment-method); [Integration security guide, CSP](https://docs.stripe.com/security/guide#content-security-policy). The installed Stripe PHP library (v21.3.2) was inspected.
- Cloudflare Turnstile: [Client-side rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/); [Testing](https://developers.cloudflare.com/turnstile/troubleshooting/testing/); server-side validation.
- Laravel 13: script nonces, events dispatched after commit, sessions and CSRF protection.
