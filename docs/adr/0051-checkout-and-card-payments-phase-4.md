# ADR-0051: Checkout and card payments (Phase 4), built without Stripe keys behind an acceptance gate

- **Status:** Accepted (owner decisions of 2026-09-27). **Phase 4 acceptance is conditional** on the Stripe acceptance gate below.
- **Date:** 2026-09-27
- **Source:** master plan sections 6.3, 7.5, 9, 11, 12, 14, 19, 23, 26.2 (cases 1, 2, 3, 4, 16, 17, 20) and 27 (Phase 4); [ADR-005](0005-own-domain-payment-element.md), [ADR-006](0006-single-use-reopenable-links.md), [ADR-017](0017-own-db-source-of-truth.md), [ADR-018](0018-card-only-mvp.md), [ADR-019](0019-gateway-port-adapter.md), [ADR-0038](0038-platform-branding-powered-by.md), [ADR-0047](0047-stripe-connection-phase-2-and-api-key-reordering.md), [ADR-0049](0049-panel-money-formatting-and-tenant-timezone.md), [ADR-0050](0050-linear-payment-flow-authorize-validate-capture.md); the approved checkout design spec (DESIGN.md of the Phase 4 job).

## Context

Phase 4 delivers the public payment page, payment attempts, 3D Secure, the completion page, the handling of Stripe's payment events, reconciliation, card-testing protection, security headers and the recording of openings. The plan asks for a technical spike with Stripe test mode first. The platform does not have Stripe test keys yet, and the owner does not want to wait for them.

## Owner decisions (2026-09-27)

1. **Build without Stripe keys.** The Stripe adapter is written from Stripe's current documentation (sources below) and exercised with Stripe's HTTP layer faked. The plan's spike becomes an **acceptance gate**: the contract tests of the `stripe` group, skipped without keys, must pass with real test-mode keys before Phase 4 is accepted.
2. **Checkout sandbox for local development and tests only**: a fake gateway on the server and a stand-in for Stripe.js in the browser, so the whole payment page can be used and tested end to end without keys. It can never run in production.
3. **Payer-facing Spanish uses "tú".** The panels keep "usted".
4. **The checkout's Content-Security-Policy includes Stripe's `*.js.stripe.com` origins**, as Stripe's CSP guide asks.

## Decision

### 1. The payment page

- `https://<pay host>/l/{token}`: the page. `/l/{token}/attempts` (pay), `/l/{token}/attempts/continue` (after 3D Secure; an addition to plan 11.5), `/l/{token}/status` (polled) and `/l/{token}/complete` (where the payer lands after paying).
- **Session and CSRF:** plan 11.5's option. The pay host has its own anonymous session with its own cookie (never shared with the panels, `SameSite=Lax`), which gives the CSRF token of the POST endpoints and remembers two things per link: the payer's own declines (for Turnstile) and whether the payer paid in this session. No payer data is kept in it.
- **Tenant resolution:** the public token is looked up by `PaymentLinkLookup` (the only cross-tenant reader of links); the tenant context of that link is then set for the rest of the request. An invalid token (malformed, unknown, another case) always runs the same query and answers the same 404 page, without merchant information.
- **Language:** the link's language, unless the payer chose one with the language switcher.
- **Closed tenants** (plan 21.3): a closed tenant's links stop taking payments at once (the page shows "no longer available" and the pay endpoint refuses), and closing the tenant cancels its active links in both modes with reason `tenant_closed` (queued, retried, idempotent; a link with a payment under way is left to finish and handled on a later run). A suspended tenant keeps collecting (ADR-013).
- **States** (plan 11.2): active, processing (polls every 3 seconds for 2 minutes, then "check again later"), paid (only the session that paid sees "Payment complete" with the amount; anyone else sees "This payment has already been made"), expired, canceled, and "not accepting payments" (long card-testing block or no gateway that can charge). An active link past its expiry is expired when the page opens. The informative states answer HTTP 200 and show the description and date, never the amount; the amount appears only while the link can be paid, during a payment and to the session that paid.
- **Status polled by the page:** the link's page state, the phase while processing and, once paid, the link's return URL.
- **Stripe.js initialization** comes from `CheckoutClientConfig`: the platform's publishable key and the connected account for `platform_onboarding` and `oauth`; the merchant's own publishable key and no account for `api_key` (plan 12.4.1, case 20). No secret key ever reaches the page.
- **Design:** implemented as the approved design spec: layout per breakpoint, no theme toggle (the page follows the operating system), copy of every state in both languages, Stripe Appearance read from the page's own colors, focus management, one polite live region, no layout shift while the card form loads.

### 2. Payment attempts

- One attempt per gateway payment (plan 7.5, 9.2). A decline does not create a new attempt: the same payment is confirmed again with another card, and every decline is recorded once in `payment_attempt_failures`.
- **At most one active attempt per link**, enforced by a unique index on a generated column (rules.md rule 9). Active means waiting for a card, waiting for confirmation, bank verification, **authorized and waiting for capture** (the stage added by ADR-0050) or processing.
- The attempt keeps the connection and the account it was created with; every later call uses them.
- A short **lease** on the attempt serializes everything that talks to Stripe about it (the payer's confirmation, a second tab, a webhook, the status sync, a void): a second tab is told a payment is in progress instead of confirming the same payment again. Each lease has an owner token; only its holder releases it, and a crashed holder's lease expires after a minute.
- **The link is reserved while a payment is confirmed.** Claiming the attempt moves the link to `processing` in the same locked transaction. Expiry skips it and cancellation answers "payment in progress" (plan 9.1). When the confirmation ends without a payment under way (decline, error, refusal) the link becomes payable again, or expired if its expiry passed meanwhile, and the attempt's waiting payment is then canceled. A payment under way keeps the link reserved until its outcome.
- **Stale reads are not applied.** Stripe never moves an authorized payment back to "waiting for the bank or a card" on its own, so such a report is only accepted from the lease holder answering its own call; from any other read (a late event, a slow status sync) it is ignored. A gateway payment whose amount or currency differ from the attempt is never applied (logged as an anomaly).
- Transitions go only through the attempt and link state machines, inside a transaction with row locks, always locking the link before the attempt. Attempts follow the gateway's current state, so any active status may follow any other (missed or out-of-order events); final statuses never change.
- Link transitions driven by attempts: a payment under way makes the link `processing`; a decline or a closed attempt makes it `active` again, or `expired` if its expiry passed meanwhile; a success makes it `paid`. **A success on an expired or canceled link wins** (ADR-006): the attempt is flagged `late_payment` (kept for the Phase 5 webhook), the anomaly is audited and logged.
- When a link expires or is canceled, its attempt still waiting for a card is closed and its Stripe payment canceled (queued, with retries). If Stripe says the payment had already succeeded, the payment wins.

### 3. The flow (ADR-0050)

1. The link must be payable (active, not past its expiry, not blocked).
2. Payer fields are validated against the configuration frozen on the link (plan 19.1).
3. Card-testing protection (section 5 below), with Turnstile verified on the server before Stripe is called.
4. The card behind the confirmation token is read (country, brand, last four digits).
5. The amount to charge is decided by one place that Phase 6 extends with currency conversion; in Phase 4 it is always the link's amount.
6. Under the link's lock the link's single attempt is reused or created, its lease taken and the payer's data stored encrypted.
7. Outside any lock: the Stripe payment is created (card only, **manual capture**, description, our identifiers only in its metadata, no application fee), then confirmed with the confirmation token. Idempotency keys are stable per attempt and operation, so a retry never creates or confirms twice.
   - **Creation carries only what the link fixes** (amount, currency, description, our identifiers), under a key fixed per attempt: after a lost answer the retry repeats the very same request and Stripe replays it. Anything that depends on the payer (the receipt e-mail) travels with the confirmation, whose key includes the confirmation token, so a payer who changes a field never meets a refused key.
   - Searching Stripe for an intent created before a lost answer is not used: the search API is eventually consistent and not guaranteed for every account, and the fixed-parameter key already makes the retry return the same intent (within Stripe's 24-hour key lifetime; an intent orphaned after that was never confirmed and cannot charge).
   - A different amount on a reused attempt (Phase 6 conversion) is an update of the same intent, with a key that names the new amount.
8. 3D Secure: the page receives the payment's client secret (never stored or logged) and lets Stripe.js run the bank's verification, then calls `continue`.
9. **Authorized:** the pre-payment validation extension point is asked, with no lock and no open transaction (rules.md rule 7b); Phase 4's default answers "not configured". **The merchant's decision is kept on the attempt at once**, so a retry, a webhook or the reconciliation never asks again and never captures a rejected authorization. The link and then the attempt are locked and re-checked: if the link was closed meanwhile (or its tenant closed) the authorization is voided instead of captured, unless Stripe says the payment already succeeded, in which case the payment wins. Approved → captured; rejected (or `fail_closed` in Phase 5) → **the authorization is voided**, nothing is charged, and the payer sees the merchant's message and the "you were not charged" notice. The void path is complete and tested so Phase 5 only plugs the callback in.
   - If the capture or the void cannot be done right now (Stripe unreachable, refused), the payer sees "your payment is processing", never an error page; the webhook or the reconciliation completes it with the kept decision.
10. The database follows what Stripe reports; Stripe's events remain the source of truth (ADR-017, ADR-0050).

Declines are always shown to the payer with one generic message; the decline code and Stripe's explanation stay in the tenant panel (plan 11.7 rule 7).

### 4. Stripe's payment events

- Handled: `payment_intent.amount_capturable_updated`, `.succeeded`, `.payment_failed`, `.processing`, `.requires_action`, `.canceled`. All are one kind for the pipeline: the handler re-reads the payment from Stripe and applies its current state (plan 14.2), which makes duplicates (case 3) and out-of-order events (case 4) harmless.
- An authorization reported by an event is completed (validation, capture): the payer who closed the tab after 3D Secure still pays once. If another process holds the attempt, one more try is queued a minute later.
- **Foreign payments** (no attempt ID in the metadata, or one the tenant does not have), frequent on `api_key` and `oauth` accounts, are stored as `ignored` with reason `foreign_object` and the reduced payload, decided from the payload before any call to Stripe (plan 14.4).
- Configuration: the Connect destinations of both modes and the endpoints on merchant accounts subscribe to these six events. After deploying Phase 4, run `axispay:stripe-sync-webhook-endpoints` so existing `api_key` endpoints receive them, and add the events to both Connect destinations in the Stripe Dashboard.

### 5. Card-testing protection (plan 11.7)

| Rule | Value |
|---|---|
| Per link | 5 confirmations in 15 minutes, then 30 minutes paused |
| Per client IP | 10 confirmations per hour, across links |
| Turnstile | Required once the link, or the payer's session, has 1 decline (critical case 16: "Turnstile after a decline"; the design spec's "after the 2nd failure" is read as "from the 2nd attempt, the first after a failure"). Configurable |
| Long block | 10 declines on a link in 24 hours block it for 24 hours; declines before the tenant last lifted a block do not count |
| Notification | E-mail to the tenant's owners and every user holding `links:cancel` |
| Unblock | Tenant panel, link detail, permission **`links:cancel`** (the permission that decides whether a link keeps taking payments), audited |
| Superadmin alerts | Phase 9; until then an alert-level log line |

Turnstile tokens are checked with Cloudflare's siteverify endpoint (secret, token, payer IP) before Stripe is called; any failure refuses (fail closed). The widget appears only when required, between the card form and the Pay button, always visible, compact on narrow screens. Cloudflare's documented test keys work in local and testing.

### 6. Openings (plan 11.6)

Every page view of an active link counts (`open_count`, first and last opening), except link previewers recognized by user agent (configurable list; an empty user agent counts as a previewer). `payment_link.opened` is recorded on the first opening and then at most once every 30 minutes, with the count and whether it was the first.

### 7. Business events recorded for Phase 5

`payment_link.opened`, `payment.failed` (per decline, with the count), `payment.succeeded` and `payment_link.paid` (with `late_payment`) are recorded in `domain_events` in the same transaction as the change, with our identifiers only. Phase 5 turns them into the outgoing webhook outbox; nothing is delivered in Phase 4.

### 8. Reconciliation (plan 12.5, ADR-0050)

Every 15 minutes, per tenant and mode with open attempts or links in `processing`: attempts not final and unchanged for 10 minutes are re-read from Stripe and applied through the same actions as the events; an authorization still not captured 15 minutes after it was authorized is voided (authorize and capture happen seconds apart, so it means the flow stopped); a link stuck in `processing` with no payment under way is made payable again, or expired. The daily comparison of the last 48 hours of plan 12.5 is left for later (it needs listing payments per connection, which is a gate item).

### 9. Payer data (plan 19)

The Phase 4 page collects the whole MVP catalog as configured on the link: e-mail, full name, phone (country code list with Mexico first; stored in E.164), company, billing address (country, street, city, state, postal code; Mexican postal codes have 5 digits), tax ID and notes. The data is stored encrypted, apart from the financial rows, with its mode (test or live), a purge date of 24 months (the purge job is Phase 8), and never logged.
- **Privacy notice required** (plan 11.3, 19.2): a tenant without a privacy notice URL collects **no** payer data. The page hides every payer field (the payment itself still works), the omission is logged, and the tenant panel warns on each affected link. Refusing to create such links was rejected: the platform default asks for an optional e-mail, so every tenant without a URL would be blocked. When the merchant collects data, the page says the merchant receives it and links the merchant's privacy notice. Deviation from plan 19.1: phone numbers are checked by structure (length, the Mexican 10-digit rule) instead of libphonenumber, and the optional MX-record check of e-mail domains is not done.

### 10. Security headers (plan 11.8, 23.4)

- A **nonce-based CSP** on every checkout response. Scripts and frames: the page itself, `js.stripe.com`, `*.js.stripe.com`, `hooks.stripe.com` (frames, 3D Secure) and `challenges.cloudflare.com`; requests: the page and `api.stripe.com`; styles and fonts: the page (styles with the nonce); images: the page and inline data; no framing of the page (`frame-ancestors 'none'`), no plugins, no base changes, forms only to the page. The same nonce is given to the asset and font tags. Google Maps (Stripe's address element) is not used.
- `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` (the token is in the URL), `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow` (and the robots meta), HSTS for two years with preload, `nosniff`.
- The web server keeps its own defaults for the other surfaces but no longer duplicates or contradicts what the application already sent; it serves the self-hosted font files with CORS so Stripe's card iframe can use Mukta.

### 11. The checkout sandbox

- On only when `AXISPAY_CHECKOUT_SANDBOX=true` **and** the environment is `local` or `testing`. **The application refuses to boot** when the flag is set in any other environment.
- Server: a fake gateway keeps payments in the cache and follows the scenario the browser stub encodes in its confirmation token: approved, declined, insufficient funds, bank verification (3D Secure) and slow processing. Captures succeed, voids cancel, idempotency keys behave like Stripe's.
- Browser: the stub replaces Stripe.js (the real one is not loaded), shows a scenario picker in place of the card form and a "sandbox bank" dialog for 3D Secure, whose answer is posted to a route that only exists while the sandbox is on.
- `axispay:checkout:demo` (sandbox only) creates a demo tenant, a sandbox connection and links in every state, and prints their URLs.

### 12. Tenant panel

The link detail shows its payment attempts, read-only, to users with `payments:read`: status, amount, card brand and last four, card country, declines with the last decline code, whether it was paid late, the Stripe payment ID (support only), and dates. A blocked link shows the block and the unblock action. A separate payments list is left to Phase 7, together with refunds.

### 13. Public API

`payment_link.payment` stays `null` in Phase 4 and `GET /v1/payments` is not added: the plan places neither in Phase 4, and the `payment` object needs FX, payer and pre-validation blocks that later phases fill. The OpenAPI contract does not change.

### 14. Additions to the gateway port (plan 12.1)

`confirmPayment` takes the return URL; `capturePayment` is new (manual capture); `cancelPayment` takes an idempotency key (rule 5); the payment status is a provider-neutral enum with the authorized stage; a payment carries the client secret only while the browser must act, the card preview, the last payment error with a reference that identifies the decline, our attempt ID read back from the metadata and the authorization's expiry. The payment event kind is one (`payment updated`) because the handler always re-reads.

## Stripe acceptance gate (conditional acceptance)

Phase 4 is accepted only when, with real test-mode keys, the `stripe` contract tests confirm (and this ADR is updated with the results):

| # | To verify | Why it matters |
|---|---|---|
| 1 | Deferred intent + ConfirmationToken with **direct charges**: the token created with the platform key and `stripeAccount` confirms a PaymentIntent created on the connected account | The whole flow (ADR-005) |
| 2 | `payment_method_preview.card.country` (and brand, last four) readable from the ConfirmationToken with the connected account | FX rule (Phase 6), panel |
| 3 | **Manual capture** with direct charges on **Mexican connected accounts** and with the **`api_key`** method (restricted key) | ADR-0050 (stop and report if not available) |
| 4 | Voiding an uncaptured authorization: resulting state, what the payer's statement shows and **whether it costs anything** (to verify: Stripe's pricing for uncaptured or canceled authorizations in Mexico) | Reject path, reconciliation |
| 5 | Authorization lifetime for online card payments (Stripe documents 7 days for customer-initiated payments; exact window and `capture_before`) | Reconciliation threshold |
| 6 | `elements.update()` with a new amount and currency before a new ConfirmationToken | Phase 6 conversion |
| 7 | Font CORS: Mukta loaded inside Stripe's card iframe from our build (`fonts` option) | Design (fallback: system font, never a CDN) |
| 8 | `use_stripe_sdk` + `handleNextAction` with a 3D Secure test card on a connected account; `return_url` behaviour | 3D Secure |
| 9 | Declines answered 402 on confirm with `last_payment_error.charge` present | One record per decline |
| 10 | Restricted key permissions: `payment_intent_write` and `confirmation_token_read` cover create, confirm, capture, cancel and reading tokens | `api_key` checkout |
| 11 | Billing details passed with the ConfirmationToken when the Payment Element is told not to collect them | Payer fields |
| 12 | Events of connected accounts delivered to the Connect destination with the pinned API version, including `amount_capturable_updated` | Section 4 |

If any of 1, 3 or 12 fails, stop and report before building on it (plan 27, ADR-0050).

## Sources consulted (2026-09-27)

- Stripe: [Finalize payments on the server](https://docs.stripe.com/payments/finalize-payments-on-the-server?platform=web&type=payment) (deferred intent, `createConfirmationToken`, `handleNextAction`, matching `captureMethod`); [Elements without an intent](https://docs.stripe.com/js/elements_object/create_without_intent) (`mode`, `amount`, `currency`, `captureMethod`, `paymentMethodTypes`, `locale`, `fonts`, `appearance`); [Create a PaymentIntent](https://docs.stripe.com/api/payment_intents/create) (`capture_method=manual`, `confirmation_token`, `use_stripe_sdk`, `return_url`, `payment_method_types`, `receipt_email`); [Place a hold on a payment method](https://docs.stripe.com/payments/place-a-hold-on-a-payment-method) (authorization windows, capture, `amount_capturable_updated`, cancel to release); [Integration security guide, CSP](https://docs.stripe.com/security/guide#content-security-policy); Stripe.js is loaded from `https://js.stripe.com/dahlia/stripe.js`, matching the pinned API version (ADR-0047). The installed `stripe/stripe-php` v21.3.2 was inspected for the PaymentIntent and ConfirmationToken fields.
- Cloudflare Turnstile: [Client-side rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/) (explicit rendering, `appearance`, `size` flexible/compact, callbacks); [Testing](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) (test site and secret keys); server-side validation with the siteverify endpoint.
- Laravel 13: CSP nonces for Vite and fonts (`Vite::useCspNonce()`), events dispatched after commit, the `web` group's session and CSRF.

## Consequences

- Payers can pay links with cards, including 3D Secure, with the authorize-validate-capture flow; tenants see attempts and declines in the panel; the platform's database follows Stripe through events and reconciliation.
- Local development and the browser tests run the full flow in the sandbox; production can never enable it.
- Phase 5 plugs the merchant callback into the extension point and turns the recorded business events into webhooks; Phase 6 plugs conversion into the amount decision; Phase 8 adds the payer-data purge and branding; Phase 9 the superadmin alerts.
- Deployment: run the migrations, `axispay:stripe-sync-webhook-endpoints`, add the six events to both Connect destinations, set `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`, and make sure `AXISPAY_CHECKOUT_SANDBOX` is unset.
