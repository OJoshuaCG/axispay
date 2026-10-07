# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Currency conversion for Mexican cards** (ADR-0063, proposed; Phase 6
  subset): a USD link of a Mexican account paid with a card issued in Mexico is
  charged in MXN, after the payer confirms the exact amount. Two modes:
  `fixed` (the link's rate, else the tenant's new `fx.fixed_rate`, no markup;
  12.30 USD x 20 = 246.00 MXN) and `banxico_fix` (the stored FIX plus the
  tenant markup). The checkout answers `requires_currency_confirmation` with
  the quote (original amount, MXN amount, rate and source, markup) and
  charges nothing until the payer confirms; a page legend shows the MXN
  amount. When the merchant cannot convert (tenant off, link opted out, no
  rate, stale or missing FIX, converted amount under MXN 10.00) the payer is
  told so and nothing is charged, instead of a Stripe decline. New tables
  `exchange_rates` and the immutable `fx_quotes`; the attempt keeps the original
  and the charged amounts and points at its quote.
- **`fx` block in payments**: `GET /v1/payments`, every `payment.*` event, the
  payment of `payment_link.paid` and the pre-payment validation body carry
  `applied`, `mode`, `source`, `rate`, `rate_date`, `markup_bps`,
  `effective_rate`, `original_amount` and `original_currency` when a conversion
  applied (`null` otherwise; `{"applied": false}` in the validation body). The
  charged `amount` and `currency` are then MXN: reconcile with both.
- **Banxico FIX** (`FetchBanxicoFixJob`, scheduled on weekdays at 12:30, 13:30
  and 17:00 Mexico City time with a 09:00 fallback, `BANXICO_SIE_TOKEN`; a
  no-op without the token). Stored once per date; a FIX that moves more than
  10 % is held for review; a FIX older than 4 days blocks `banxico_fix`
  conversions; both alert the superadmins. The checkout never calls Banxico.
- **Tenant "Payment settings" page** (`settings:manage`): conversion on or off,
  mode, fixed rate, markup and quote validity, and the link expiration default
  and maximum. Audited as `tenant.payment_settings_updated`.
- Sandbox: a `foreign` card scenario (issued in the US, charged in USD); the
  other sandbox cards are Mexican.

- **`payment.canceled` event** (ADR-0062, proposed): sent every time an
  authorized payment (or a 3D Secure step) is released and will not be
  charged, with `data.reason` (`merchant_rejected`, `validation_failed`,
  `capture_window_elapsed`, `link_closed`, `abandoned_action`, or
  `gateway_canceled` when the gateway released it on its own) and the same
  `pay_` ID the validation call carried. Before, a void sent nothing, so an
  integrator that credited or reserved something when it approved could not
  know it had to undo it. Endpoints subscribed to an explicit list of events
  must add it.
- **Payment events carry `client_reference_id`, `captured_at` and `fx`**: the
  payment of every `payment.*` event and of `payment_link.paid` now has the
  link's reference, the capture time (null until it succeeds) and an `fx`
  block (null unless a currency conversion applied, ADR-0063).
- **`GET /v1/payments/{id}` and `GET /v1/payments`** with the new use of the
  `payments:read` scope: status (including `processing`, `requires_capture`
  and `canceled`), link, `client_reference_id`, amount, currency, `fx`, card
  brand and country, capture time. The list is newest first with the usual
  cursors, filters `status`, `payment_link` (the payments of one link) and
  `created[gte|lte]`. No gateway identifiers, last digits or payer data.
  Documented in the integration guide, the webhooks guide and the OpenAPI
  file.

- **One setting for the pre-payment validation timeout** (ADR-0061, proposed):
  `AXISPAY_VALIDATION_TIMEOUT_SECONDS` (30 s by default, 5 to 60) replaces the
  hardcoded 5 s. The checkout request budget and lease, the job timeouts, the
  queue `retry_after`, the unique locks of the payment jobs and the container's
  nginx, PHP-FPM and worker limits are all derived from it
  (`ValidationTimeouts`; 5 reproduces the previous values exactly). The
  application refuses to boot with a value out of range or an inconsistent
  limit, and `php artisan axispay:validation-timeouts` prints the derived
  limits for the container entrypoint. The default changes the wait of every
  merchant from 5 to 30 s; set 5 to keep the old behavior.
- **The pre-payment validation body names the payment attempt**:
  `data.payment.id` (`pay_…`), the same in the immediate retry and in every
  other call about that attempt, next to the already sent link ID,
  `client_reference_id` and `livemode`. Documented in the integration guide,
  the webhooks guide and the OpenAPI file.

- **API integration guide** (`docs/api/integration-guide.md`): a self-contained
  guide for the developer of another system, covering authentication and
  scopes, creating and following payment links, idempotency, errors, the
  events API, outgoing webhooks and the pre-payment validation callback, with
  examples in curl, PHP, Node and Python.

- **Event history in the API** (Phase 5, ADR-0060): `GET /v1/events` and
  `GET /v1/events/{id}` with the `events:read` scope. An event is the exact
  body that was or will be sent by webhook (frozen when the event happened),
  so integrators can confirm a webhook or catch up after downtime. Newest
  first, same cursor pagination and rate limit as the other lists, filters
  by `type` and `created[gte|lte]`, the last 30 days only, the test `ping`
  event never shown, and other accounts' or the other mode's events answer
  `404`.
- **API contract and merchant guide completed for Phase 5**: the OpenAPI file
  documents the events endpoints, the outgoing webhooks (headers, body,
  every event type, the `pre_validation` block of payments) and the
  pre-payment validation callback with its answer; the webhook guide adds
  signature verification and validation server examples in PHP, Node and
  Python, and the stock-reservation recommendation.
- ADR-0057, ADR-0058 and ADR-0059 accepted by the project owner
  (2026-10-02).

- **"How it works" in the webhook screens**: a help slide-over on the webhook
  endpoints list, each endpoint's page and the pre-payment validation page,
  also reachable from their empty states. For the merchant's developer:
  when each call happens in the payment flow, the set-up step by step, the
  headers and body with examples built by the same code as the real
  requests, how to verify the Standard Webhooks signature, the delivery and
  retry rules, the URL requirements, the answer the validation expects, its
  limits and failure policies, and what "Test validation" sends. Every
  number is read from the configuration.

- **Phase 5 panel screens** (ADR-0059):
  - Settings → Webhooks: the endpoints of the current mode with their
    events, status and a "failing since" badge; create and edit (every event
    or a chosen list), the signing secret shown once, reveal and rotate after
    re-authentication, disable, enable, delete, and "Send test event" with
    its result (delivered or not, HTTP status, time, error, start of the
    answer). Each endpoint's page has the delivery log with a status filter,
    the details of each attempt and a manual resend.
  - Settings → Pre-payment validation: URL, failure policy explained in
    plain words, default for new links, the secret shown once and its
    rotation, removal with a warning about links created with validation,
    "Test validation" with status, time, decision, format, errors, warnings
    and the start of the answer, the alert after repeated failures and the
    recent calls.
  - The validation calls of each link on its detail (plan 15.8.7), for users
    who manage webhooks.
- **Payments in the tenant panel** (ADR-0059, brought forward from Phase 8):
  a read-only history of the current mode's payments (`payments:read`), one
  row per payment attempt with date, link, amount, status, card and
  validation result; filters by status, currency, validation result and
  date; search by link or payment ID; a detail with the payment's timeline
  (declines, authorization, validation calls, charge or release, events
  sent). Each attempt on a link's detail opens it.
- **Merchant guide to webhooks and pre-payment validation**
  (`docs/guides/webhooks.md`).

- **Outgoing webhooks, backend** (Phase 5, ADR-0057):
  - Webhook endpoints per tenant and mode (at most 5 per mode), with the
    events they subscribe to or all of them, a signing secret shown once,
    stored encrypted, revealed again only after re-authentication, and a
    rotation that keeps the previous secret valid for 24 hours.
  - Every business event is published in the same transaction as the change
    and sent, signed as Standard Webhooks, to the subscribed endpoints; failed
    attempts are retried on the plan's calendar (8 attempts in about 27
    hours), a sweeper every minute recovers what was never published or
    queued, and an endpoint failing for 5 days is disabled and its managers
    are e-mailed.
  - Manual resend of a delivery and a synchronous test event (`ping`) whose
    result the panel can show.
  - SSRF protection of merchant URLs at registration and before every
    attempt: https only, allowed ports, no IP addresses, no private or
    reserved destinations, the checked addresses pinned for the request, no
    redirects; a blocked destination is never retried.

- **Pre-payment validation, backend** (Phase 5, ADR-0058):
  - One validation URL per mode with its own signing secret (shown once,
    stored encrypted, rotation keeping the previous one valid for 24 hours),
    a default for new links, and a failure policy: do not charge (default)
    or charge anyway.
  - After the card is authorized, links that use it call the merchant with a
    signed request (5 seconds in total, one retry only when the connection
    could not be opened, SSRF protection shared with the webhooks); an
    approval captures the payment, a rejection releases the authorization,
    shows the merchant's message and can cancel the link, and a failure
    follows the merchant's policy. Every call is logged for 30 days.
  - `POST /v1/payment_links` now accepts `pre_payment_validation: true` when
    the mode has a validation URL, and applies its default when omitted.
  - An e-mail to the managers and a panel alert after 10 failures in a row
    (at most one e-mail per hour), a "Test validation" action with the
    status, latency, decision and format problems, and a `pre_validation`
    block in the outgoing payment events.

- **Legal texts on the payment page** (ADR-0056, brought forward from
  Phases 8 and 10):
  - A "Legal" page in the tenant panel (Settings) where the merchant
    publishes a privacy notice and terms and conditions, each as a text
    (simple Markdown, up to 50,000 characters, HTML removed) or a link. New
    tenant permission "manage the privacy notice and terms" (`legal:manage`),
    held by owners and admins; changes are audited.
  - The payment page shows "Privacy notice · Terms" below the amount in every
    state: a text opens in a dialog (with its own page under the link for
    payers without JavaScript), a link opens in a new tab.
  - Payer fields are collected again as soon as the merchant publishes a
    privacy notice, as a text or a link; without one nothing is collected, as
    before. The warning on affected links now points to the Legal page.
  - A "Legal" page in the platform panel for the platform's privacy notice
    and terms (new platform permission `platform:legal:manage`, password
    again, audited), shown on the pay host's public `/legal` page. "Powered
    by" on every payment page links there while one of them exists.
  - The two-card checkout with a theme choice is part C, below.

- **The merchant's logo on the payment page** (ADR-0056 part B, brought
  forward from Phase 8):
  - A "Brand" page in the tenant panel (Settings) where owners and admins
    (`settings:manage`, plan 17.1) upload the company logo and, optionally, a
    version for dark theme; each can be replaced or removed on its own
    (removing the logo also removes its dark version). Same checks as the
    platform logo (PNG, JPEG or WebP recognized from the content, SVG
    refused, up to 1 MB and 2000 × 2000), converted to a clean PNG and kept
    at up to 800 × 240. A preview shows it on a light and a dark page.
    Uploads and removals are audited with the image's size and fingerprint
    only. Read-only for suspended or closed tenants and while a platform
    admin views as the tenant.
  - The payment page (and the merchant's legal document pages) show it at
    the top, centered and large, with the merchant's name as its text
    alternative; in dark theme the dark version, or the logo on a light plate
    when there is none. Without a logo the merchant's name is shown as
    before. Both panels keep the platform logo.
  - The logo is served only on the pay host, under the link's own address,
    cached for a year (every upload gets a new address), without cookies.

- **A redesigned payment page** (ADR-0056 part C):
  - Two cards on a light-gray canvas: the payment details (merchant, the
    description, a visible "Total to pay", the expiry and the legal links) on
    the left, the payer fields, the card form and Pay on the right; stacked
    on phones and tablets, side by side from 1024px. The summary is no longer
    sticky. While a payment is processing both cards stay (the status on the
    right); paid, expired, canceled and blocked links, the "link not found"
    page and the error pages show one card.
  - A light, dark or system theme choice next to the language switcher, on
    every pay-host page. Light by default, as on the other pages (ADR-0044);
    "System" follows the device. The choice is remembered in the browser,
    and Stripe's card form and the bot check follow it.
  - The merchant's logo (or name) centered and large at the top.
  - A large total wraps its currency code below the number instead of
    overflowing a 320px phone.
  - A lighter footer: the merchant's help line ("Questions about your
    payment? Email …") and, on a second line (one row from 1024px), "Powered
    by" · "Processed by Stripe". "Powered by" opens the platform's legal page
    in a new tab.
  - The legal-text window is a full-screen sheet on phones and a centered
    panel from 640px, with the merchant, the title, the scrollable text and a
    Close button; the page behind it does not scroll.
  - The card-form placeholder is shaped like Stripe's form.

- **`axispay:doctor`** warns when payment links would use a different scheme
  than `APP_URL` (for example `http://` without TLS but links on `https://`
  because `AXISPAY_PAY_BASE_URL` is empty), and fails when
  `AXISPAY_PAY_BASE_URL` has no scheme.

- **Sharper, wider platform logo in the sidebar** (ADR-0053 amendment of
  2026-09-29): the sidebar logo now takes the whole sidebar width, and the
  platform logo is stored at up to 1024 × 512 (tenant logos keep 400 × 120).
  A logo uploaded before this change must be uploaded again to benefit.

- **Panel layout and favicon** (ADR-0054, ADR-0053 amendment):
  - A larger platform logo on the sign-in, two-factor and invitation pages
    (up to 320 px wide, the name centered under it).
  - From 1024 px, both panels show a full-height sidebar with the platform
    logo; the top bar sits beside it and the account notices stay full width
    on top. Phones and tablets are unchanged.
  - The logo sizes are design tokens, adjustable in one place.
  - The superadmin can upload the platform favicon on the Branding page
    (PNG, JPEG or WebP, 32 to 2000 px, SVG and ICO refused); three sizes are
    generated and linked from every page, with the default favicon when none
    is uploaded. Upload and removal need the password again and are audited.

- **Stripe connection, API keys method:** a "View required permissions"
  help on the Stripe connection page (also available, collapsed, inside the
  connect and update keys forms) with the steps to create the restricted key
  in Stripe, each permission it needs and why, the permissions it must not
  have (payouts, transfers, balance) and a link to Stripe's guide. EN and ES.
  It lists exactly what the key validation checks.

- **Platform logo** (ADR-0053, brought forward from Phase 8): a "Branding"
  page in the platform panel, for superadmins only (new platform permission
  "manage branding"), to upload the platform logo and an optional dark mode
  logo, remove them, and choose what the brand shows (logo and name, logo
  only, name only). Both panels (including sign-in, two-factor and invitation
  pages) and the payment page's "Powered by" line follow it; without a logo
  the name is shown, and e-mails keep the name only. Uploads: PNG, JPEG or
  WebP (SVG refused, real type checked), at most 1 MB and 2000 × 2000,
  converted to a clean PNG without hidden data. Every change needs the
  password again and is audited. The server image now includes the image
  library the conversion needs.

- **Phase 4 — Checkout and card payments** (ADR-0051; acceptance conditional
  on the Stripe acceptance gate with real test-mode keys):
  - Payment page on the pay host in every state of plan 11.2, following the
    approved design (`docs/frontend/checkout-design.md`): merchant header,
    order summary, payer fields (the whole MVP catalog, encrypted), Stripe's
    card form (card only, no wallets or Link), a security check when
    required, platform footer. EN and ES (payer-facing Spanish uses *tú*);
    follows the device's light or dark setting.
  - Authorize, validate, capture (ADR-0050): one payment under way per link,
    3D Secure in the page, the pre-payment validation point (not configured
    until Phase 5), capture, and a complete void path for rejections.
  - A payer is never charged twice: repeated requests (lost answers,
    retries, a second tab, a crash) continue from the payment's real state.
  - Capture window: an authorization is captured within 15 minutes of being
    authorized, otherwise voided (a payment Stripe reports as succeeded
    wins).
  - The link is reserved while a payment is confirmed (it cannot expire or be
    canceled mid-payment); a late success on a closed link wins and is
    flagged; closing a tenant cancels its active links.
  - If Stripe is slow, the page answers "your payment is processing" within
    50 seconds instead of failing; a confirmation whose answer was lost is
    never shown as an error.
  - Stripe's six payment events are read back from Stripe and applied
    (duplicates and out-of-order events are harmless); payments the platform
    did not create are ignored; stored events carry no payer data and are
    encrypted.
  - Event recovery: stuck events are queued again every 5 minutes, events of
    not-yet-connected accounts are routed once connected, and failed events
    can be retried by the operator (audited); an event is processed once at
    a time.
  - Reconciliation every 15 minutes of the payments under way (oldest visit
    first, bounded per run): missed events, authorizations past their window,
    3D Secure left open for 30 minutes, links left reserved.
  - Card testing: 5 confirmations per link in 15 minutes (then 30 minutes
    paused), 10 per IP per hour (IPv6 /64), 20 unrecognized cards per network
    per hour, the security check from the first try after a decline, and a
    24-hour block after 10 declines within 24 hours; only confirmations that
    reach Stripe count.
  - Pay-host request limits per group (page and completion 60/min, status
    90/min, Pay and 3D Secure continuation 30/min), answered in the link's
    language with the real wait.
  - Tenant panel: payments on the link detail (card brand and country by
    name, declines explained in words, "needs review" reasons), the
    card-testing block and **Unblock payments** with re-authentication.
  - E-mails in the tenant's language: the blocked-link e-mail (with a button
    to the link) and the gateway connection e-mails.
  - Business events recorded for Phase 5 as frozen snapshots: link opened,
    payment processing, payment failed (generic failure code), payment
    succeeded, link paid.
  - Page security: nonce-based CSP with Stripe's and Cloudflare's origins, no
    framing, no referrer, no caching, no indexing, two-year HSTS; the
    payer never sees framework text (own 404, 419, 429, 500 and 503 pages).
  - Data: payer data kept 24 months (purge in Phase 8), the payer's IP and
    browser for card-testing investigations (cleared 90 days after the
    attempt closes, from Phase 8), the card fingerprint for forensics.
  - Production refuses to start without the security-check keys or a
    trusted proxy, or with the sandbox on.
  - `AXISPAY_TURNSTILE_ENABLED=false` temporarily turns the bot check off
    until a Cloudflare account exists (ADR-0052): no Turnstile keys needed,
    a warning in `axispay:doctor`, every other card-testing limit unchanged.
  - Checkout sandbox for local development and tests (a stand-in for Stripe
    on the server and in the browser, a demo command); never in production.
  - Stripe acceptance gate: contract tests for the automated items (both
    connection methods) and a list of items checked by hand (ADR-0051,
    section 12).
  - Deployment: queue and worker time limits realigned (`retry_after` 150 s,
    jobs 115 s, workers 120 s) with longer stop grace periods; an "Upgrading
    to Phase 4" list, a scheduler table and a Phase 4 operations section in
    the deployment guide.

- Incoming Stripe webhooks are mandatory (ADR-0050, owner decision
  2026-09-27): disputes, Dashboard refunds, payers who close the tab after
  3D Secure, network cuts during capture and connected-account changes only
  reach the platform as Stripe events; reconciliation is a safety net.
  - `axispay:doctor` checks the Stripe webhooks per mode: an error when a
    mode is in use without a valid Connect signing secret (never printed),
    a warning when connections that can charge received no event for 7 days
    (`gateways.stripe.provider_events.silence_warning_days`), and the time of
    the last Stripe event. It also prints the pinned API version and the
    events the Connect destination must send
    (`gateways.stripe.connect_webhook_events`).
  - Platform panel, tenant page: **Last Stripe event** per gateway
    connection (UTC) and a **No events in 7 days** badge on a connection
    that can charge but has gone silent.
  - Deployment guides: the Connect webhook destination is a required step,
    with a checklist per mode, how to confirm events arrive, and a
    troubleshooting entry for "no Stripe events received".
  - New indexes on `provider_events` for these checks.
- ADR-0050 (decision only, implementation in Phases 4 and 5): payments are
  linear — the card is authorized first, the merchant's optional pre-payment
  validation runs next, and the charge is captured only after approval; a
  rejection voids the authorization, and a failed final webhook never
  triggers an automatic refund.
- **Phase 3 — Payment links API** (ADR-0048, ADR-0049):
  - `POST /v1/payment_links`, `GET /v1/payment_links/{id}`,
    `GET /v1/payment_links` (filters and cursor paging) and
    `POST /v1/payment_links/{id}/cancel` on the API host, with the field and
    business rules of plan 10.5, amounts as decimal strings, `plink_` IDs and
    the error codes of plan 10.4. OpenAPI contract in `docs/api/openapi.yaml`.
  - API keys (`axp_test_…` / `axp_live_…`) owned by the tenant: shown once,
    scopes, mode taken from the prefix. Owners are e-mailed when a live key
    is created or revoked.
  - Idempotency (`Idempotency-Key`, required to create links) with replays
    for 24 hours; a key that created a link can never create another one.
  - Rate limit of 100 requests per minute per API key; failed
    authentications are limited per IP (30 per minute).
  - A suspended tenant keeps reading and canceling but cannot create links;
    a closed tenant's API is read-only for 30 days, then its keys answer
    `401`.
  - Links expire automatically (checked every minute); disconnecting a
    gateway cancels the active links of that mode.
  - Tenant panel: **Payments → Payment links** (list, search, filters,
    detail, manual create, cancel) and **Settings → API keys** (create with
    re-authentication, key shown once, revoke). Money reads as number + ISO
    code (`12,500.00 MXN`, with `,` thousands and `.` decimals in both
    languages); dates show in the tenant's time zone.
- Limits decided by the owner (ADR-0048): link expiration 7 days by default,
  15 minutes to 90 days; minimum amounts per Stripe (USD 0.50, MXN 10.00);
  maximum USD 10,000.00 and MXN 200,000.00 per link. Tenants may lower the
  expiration and maximum amount in their settings.
- **Phase 2 — Stripe connection** (ADR-0047). Settings → Stripe connection in
  the tenant panel (`gateway:manage`, re-authentication for every change):
  - **Create or connect with Stripe (recommended)**: a Standard-equivalent
    connected account (MX by default, `AXISPAY_STRIPE_ALLOWED_COUNTRIES`),
    Stripe-hosted onboarding with return and refresh URLs, status and pending
    requirements, continue onboarding, refresh status, disconnect.
  - **Advanced: use my API keys** (`api_key`, brought forward from Phase 4B):
    restricted key + publishable key with a risk notice; `sk_` keys always
    refused; mode, account, country, pk ↔ rk and permission checks;
    excessive permissions need an extra confirmation in live mode; the key
    is stored encrypted with the dedicated `GATEWAY_CREDENTIALS_KEY` and only
    shown as `rk_…last4`; a webhook endpoint is created on the merchant
    account; update keys; disconnect deletes the endpoint and erases the
    keys; daily health check (`axispay:gateways:check-api-keys`) marks
    revoked keys `invalid_credentials`.
- Gateways module: `PaymentGateway` port, `StripeGateway`,
  `StripeClientFactory` (Connect / direct / platform contexts, pinned API
  version `2026-08-26.dahlia`, idempotency keys on every create),
  `GatewayCredentialsEncrypter` with key versions and
  `axispay:rotate-gateway-credentials-key`, `gateway_connections` table.
- ProviderEvents module: `POST /webhooks/stripe/connect/{mode}` and
  `POST /webhooks/stripe/direct/{connection_id}` on the API host (signature
  on the raw body, one row per event in `provider_events`, 200 at once,
  `ProcessProviderEventJob` on the `critical` queue re-reads Stripe);
  handlers for `account.updated` and `account.application.deauthorized`;
  unroutable events are stored and logged at alert level.
- A tenant in `pending_onboarding` becomes `active` automatically when a
  gateway connection can charge (audited, system actor).
- Platform panel: the tenant view lists its gateway connections (read-only,
  no secrets).
- E-mails to owners and `gateway:manage` holders: Stripe connected,
  disconnected, restricted, invalid API keys, key with excessive permissions.
- `PasswordField::forSecret()` for pasted secrets (API keys).
- Stripe contract tests (`./vendor/bin/pest --group=stripe`, run only with
  test-mode keys exported in the shell).
- Dependency: `stripe/stripe-php` ^21.3 (21.3.2).
- Security review fixes (ADR-0047): the Stripe page erases the typed
  restricted key from its Livewire state on every response (it came back in
  the snapshot when the form failed validation); direct webhook endpoints
  subscribe to `account.updated` only, unhandled and unroutable events are
  stored with a reduced payload, and `axispay:provider-events:purge` (daily)
  applies the retention of plan 14.4; `axispay:stripe-sync-webhook-endpoints`
  updates the events of existing endpoints; a revoked old key no longer marks
  freshly rotated keys invalid; failed connects and key updates leave no row
  or remote endpoint behind; key updates use a fresh idempotency key per
  attempt.

### Changed

- **Link expiration maximum is 60 days** (it was 90): `axispay.limits.max_expiration_hours`
  1440. A tenant default or maximum stored above it is clamped when read; links
  already created keep their expiry (ADR-0048 amended).
- **Link creation and conversion**: `fx_not_available` now only answers MXN links
  and tenants with conversion off (the platform switch is gone); a USD link
  without `fx` of a tenant with conversion on takes the tenant's default mode;
  `fx_rate_invalid` (no usable fixed rate, or more than 30 % from the latest
  FIX) and `amount_below_minimum_after_conversion` are now returned.

- The panel texts of the pre-payment validation (how it works, failure
  policies, the timeout failure) and the guides read the timeout in force
  instead of saying "5 seconds". The stale `QUEUE_TIMEOUT` and stop grace
  period values of ADR-0035 and ADR-0036 are corrected.
- The Spanish copy of the webhook and pre-payment validation errors and
  e-mails now uses the panels' formal register (*usted*).
- README: the phase table shows Phases 2 to 4 as done, 4B as partial and 5 in
  progress.
- **Database:** the tenants' `privacy_notice_url` column is replaced by the
  `tenant_legal_documents` table; existing addresses are moved over as link
  documents by the migration. Run the permission catalog seeder on deploy so
  owners and admins get `legal:manage`.
- The checkout footer no longer repeats the merchant's privacy notice link;
  it sits under the order summary with the terms.

- **Stripe connection, API keys method, test mode** (ADR-0055): a test-mode
  connection made with API keys is active, and payment links and the payment
  page work, even when the Stripe account is not activated yet (Stripe
  accepts test payments on it). The Stripe connection page shows an
  informational notice ("your Stripe account is not activated; test payments
  work") instead of "Stripe paused payments", and no "payments paused" e-mail
  is sent. Stripe's own values are still stored and shown as reported. Live
  mode, and connections made through the platform in either mode, still need
  Stripe to enable charges. A test-mode connection already marked restricted
  for this reason becomes active at the next daily key check, the next account
  update from Stripe, or at once with "Refresh status" on the Stripe connection page.
- Stripe connection page: the pending requirements section also lists the
  items Stripe is still verifying, apart from the items that are due.

- Creating a tenant requires an **owner e-mail** (ADR-0045, plan 17.2), in the
  admin panel and in the `CreateTenant` action. An address that already
  belongs to a user is refused before anything is written. Existing tenants
  without an owner keep working.

- Panels and Blade pages default to the **light** theme (ADR-0044); a saved
  choice is kept, and "System" still follows the OS. The panels have a
  visible light/dark/system control on the sign-in pages and in the topbar
  (from 768px; below that, in the user menu).
- Panel type scale for Mukta: table cells, sidebar, labels and buttons are
  15px below 1024px and 16px from 1024px (were 14px), badges 13px (were 12px),
  base text 17px on desktop.
- Language switcher shows the codes EN / ES at every width, in a compact
  segmented control shared with the theme controls (36px tall with a mouse on
  large screens, 44px targets on touch). On the sign-in pages the controls
  are right-aligned.
- Panel surfaces: canvas, raised and sunken layers (new `canvas`, `raised`,
  `sunken` tokens), a bordered sidebar with a clearer active item, sunken
  table header rows, a soft background on the sign-in page, a brand tile
  next to the product name, semibold page titles.
- Panel copy and tables: sentence-case labels ("Crear cliente",
  "Administradores"), page titles that name the record, rows that open the
  view page (the "View" row action is gone), empty states, a two-column
  tenant profile section with a copyable monospace ID, and Spanish
  "Inicio" / "Inicie sesión" / "Iniciar sesión" instead of Filament's
  "Escritorio" / "Entre a su cuenta" / "Entrar".

- Invitations created by the platform (on tenant creation or later) are
  attributed to the acting platform admin in the audit log (before: the
  current guard, or `system` from the CLI) and now count against the
  per-tenant invitation limit, which moved to `InvitationThrottle`.

### Fixed

- Pre-payment validation page: "Configure" did nothing while validation was
  not configured and had no calls. The page has a table, so Filament leaves
  the action dialogs to the table, which that state hides; the page now
  renders them itself.
- The event descriptions of the webhook event picker showed their
  translation key instead of the text (event names contain a dot).

- Phase 5 review fixes (ADR-0057, ADR-0058):
  - Webhook and validation URLs are stored and called in one canonical form
    (lowercase, international domains in ASCII), so the address pin always
    matches the name called; a domain with a final dot is refused.
  - The domain lookup counts towards the 10-second (webhooks) and 5-second
    (validation) budgets; a lookup that uses it up is a timeout.
  - A merchant's `cancel_link` is applied whoever releases the authorization,
    including Stripe's later notification when the void got no answer, and
    in the same step, so it never cancels a link a newer payer is using.
  - A new endpoint no longer receives backlog events that happened before it
    existed; the outbox sweeper reads unpublished events through an index.
  - A delivery job that runs a moment early sends the attempt; one that runs
    earlier goes back to the queue for the time left.
  - Signing secrets and validation bodies are hidden from stack traces.
  - Panel: Spanish copy says "clave secreta de firma" and drops jargon; test
    results say what to do next and, for validation, the decision taken;
    events are labeled by meaning with their code below; a new endpoint shows
    "No deliveries yet"; the validation page groups rotate and remove under
    "More"; the payment detail shows declines only when there were some.

- Platform panel: **View as user** could not be found. A tenant row opened
  the edit page instead of the view page (Filament's default record URL
  takes the first visible view/edit *table* action, and only Edit was left
  after the View row action was removed), and the action lives on the view
  page. Rows now open the view page explicitly, and the edit page has a
  View button. The action shows for every tenant whose status allows panel
  access (including pending onboarding) and is disabled, with a tooltip,
  while the tenant has no active user.
- Panels: the 2FA set-up page showed two control bars (the sign-in page's
  language/theme row and Filament's signed-in header with the test/live
  selector). Every simple page now shows exactly one right-aligned bar:
  language and theme (plus the user menu when signed in); the test/live
  selector is not shown during account set-up.
- Invitation page: the password could not be revealed.
- Panels: keyboard focus on sidebar items, tabs and topbar buttons is a
  visible 2px outline (Filament showed only a faint background tint).
- Panels: the language switcher on the sign-in pages was centred instead of
  right-aligned.
- Panels: an empty table toolbar row above the search field on phones.
- Panels: `419 Page Expired` on the second Livewire request of a page (a
  second sign-in attempt after a wrong password, the 2FA set-up, any action
  after the first one). The shared panel middleware was registered as Livewire
  persistent middleware, so every Livewire update re-ran `EncryptCookies` and
  `StartSession` on Livewire's internal request: the already-decrypted session
  cookie failed to decrypt, the store switched to a new, never-saved session id,
  and the next request failed the CSRF check. The middleware is no longer
  persistent (Livewire's update route runs the `web` group, and Filament makes
  its own panel middleware persistent). Reproduced and verified with the
  production image, `web` and `all-in-one` roles, direct and behind a proxy.

### Added

- **Password field component** (ADR-0046): `<x-password-input>` on Blade
  pages and `PasswordField` in Filament, used by the invitation page, the
  panels' sign-in, profile and re-authentication. Accessible reveal button
  (44px, `aria-pressed`, "Show password" / "Mostrar contraseña"), and for new
  passwords a live requirements checklist and a "passwords do not match"
  hint. Shown on `/design-system`.
- `PasswordPolicy` (Identity): the single password policy (12 characters,
  data-leak check when enabled), now also enforced inside `AcceptInvitation`
  and `CreatePlatformAdmin`; `Password::defaults()` delegates to it.
- Platform panel: **View as this user** on each active row of a tenant's
  Users tab (same rules and `StartImpersonation` as the header action).
- Platform panel: **Owner** column on the tenant list (Active / Pending
  invitation / None) and a **No active owner** filter, computed in the list
  query without N+1 (`PlatformAdmin\Services\TenantOwnership`). The tenant
  view warns while there is no active owner, shows the owner invitation's
  expiry and offers **Invite owner** and **Resend owner invitation**
  (ADR-0045).
- **Make owner** on a tenant's Users tab (superadmin, `PromoteToOwner`):
  adds the owner role to an active user and keeps their other roles; needs a
  reason and a fresh re-authentication; not on closed tenants; audited as
  `owner.promoted` in the platform and tenant logs; e-mails the tenant's
  owners and the user (queued, tenant language). It does not go through the
  tenant-side `RoleGrantGuard`, which still stops admins from granting owner.

- Tenant lifecycle in the admin panel (ADR-0043). **Edit** a tenant's profile
  (legal and display name, time zone, default language, support e-mail) from
  the list or the view page (`UpdateTenantProfile`, superadmin, not on closed
  tenants, audited as `tenant.updated` with the changed field names; the
  support e-mail value is never stored in the audit entry). **Invitations**
  tab on the tenant page: invite an owner to an existing tenant
  (`InviteTenantOwner`, through `InviteUser` in the tenant context, owner role
  only), **Resend** pending or expired invitations (`ResendInvitation`: new
  token and 72-hour expiry, the old link returns 410 at once;
  `invitation.resent`) and **Revoke** pending ones (`RevokeInvitation`).
  Read-only **Users** tab (roles, active, 2FA yes/no, last sign-in). E-mails
  are masked for `support_readonly` (plan 17.4). New policy abilities
  `update`, `viewMembers`, `sendInvitations`, `revokeInvitations`. Tenants
  cannot be deleted (audit log FK `RESTRICT`); the view page says so next to
  the status.
- `php artisan axispay:mail-test {email} {--queue}`: sends a test e-mail now
  (or through the queue) and prints the mail settings without the password;
  exits 1 with the transport error on failure. Outgoing-mail section (cPanel
  example with `MAIL_SCHEME=smtps`) and "invitation not received"
  troubleshooting in both Dokploy guides.
- Production account recovery (ADR-0041): `php artisan axispay:reset-2fa` and
  `php artisan axispay:reset-password` for platform admins and tenant users, in
  every environment. Mandatory reason (stored in the audit log as
  `two_factor.reset` / `password.reset`, actor `system`), confirmation
  (default No; `--force` only with `--reason`), `--type` when the e-mail
  exists in both tables, tenant lookup through the audited platform context.
  Both revoke every session of the account, rotate its "remember me" token and
  clear its per-account sign-in throttle. The password is only read twice from
  a hidden prompt and follows `Password::defaults()`. New `ResetTwoFactor` and
  `ResetPassword` Actions; `axispay:dev-reset-2fa` now uses `ResetTwoFactor`.
  Account recovery runbook and troubleshooting rows (lost authenticator,
  forgotten password, throttling, clock skew, `APP_KEY` rotation) in both
  Dokploy guides.
- `php artisan axispay:doctor`: read-only deployment diagnostics (session and
  cookie configuration per host, session table, cache, trusted proxies, queue,
  pending migrations, container role, non-reversible `APP_KEY` fingerprint);
  exits non-zero on an error. Troubleshooting rows for `419` in both Dokploy
  guides.
- Panel session resilience (ADR-0040): a Livewire `419` reloads the page
  instead of showing Livewire's untranslated prompt (loop guard: once a minute
  per tab), and `GET /session/ping` (admin and app hosts, `204`, `no-store`,
  throttled) keeps the session of a visible, actively used tab alive.

- `all-in-one` container role for staging and local/test Dokploy servers
  (ADR-0039): supervisord runs php-fpm, nginx, `worker-critical`,
  `worker-default` and the scheduler as `www-data` in one container, with
  graceful stop (workers first, web last) and a health check that requires
  `/up` plus every worker and the scheduler. The `worker` role and the
  all-in-one workers build `queue:work` from one entrypoint function.
  New guide `docs/deployment/dokploy-all-in-one.md`, including a local test
  deployment without TLS (`.test` hosts, `SESSION_SECURE_COOKIE=false`,
  optional mkcert). Convention: every scheduled task uses
  `withoutOverlapping()` and `onOneServer()`. Production keeps four
  Applications (ADR-0036).
- ADR-0038 (decision only, implementation in Phases 4 and 8): the platform
  logo is managed from the superadmin panel, and the checkout always shows
  the platform in its footer ("Powered by" + logo and name) with the
  merchant's brand in the header; `show_platform_badge` becomes
  `platform_badge_style` (`standard` / `subtle`) and can no longer hide it.
- Production deployment (ADR-0035, ADR-0036):
  - Multi-stage `Dockerfile` (PHP 8.4 FPM + Nginx, Composer `--no-dev` with
    `check-platform-reqs`, pnpm + Vite build, non-root, no `.env`) and
    `.dockerignore`. One image, roles `web`, `worker`, `scheduler`, `release`
    and `artisan` selected by `CONTAINER_ROLE` or the first argument
    (`docker/app/entrypoint.sh`); role-aware `HEALTHCHECK`; runtime caches
    built at container start; migrations with the migrator connection before
    the web role serves (`RUN_MIGRATIONS=true`), other roles wait for them.
  - `config/trustedproxy.php` (`TRUSTED_PROXIES`) for TLS termination at a
    reverse proxy; security headers (HSTS, nosniff, frame options, referrer
    and permissions policies) in the image's Nginx.
  - `docs/deployment/dokploy.md`: step-by-step Dokploy guide (environments,
    variables, domains, Swarm health check and update settings, rollbacks,
    security checklist, troubleshooting).
  - Project documentation: `README.md`, `docs/README.md` (index) and
    `docs/architecture.md`.
- Phase 1 tenancy, identity and access (master plan section 27):
  - `Tenancy` module: `tenants` table and `TenantStatus` (plan 21.3) with
    `CreateTenant` / `ChangeTenantStatus` actions (superadmin only, reason,
    double confirmation to close, audited, owners notified); scoped
    `TenantContext` with audited `runAsPlatform()` and `runAsTenant()`;
    fail-closed `BelongsToTenant` and `BelongsToMode`; `TenantAware` jobs with
    `RestoreTenantContext`; tenant panel resolution and session test/live
    selector (`SwitchLivemode`, audited); `TenantSettings` DTO;
    `config/tenancy.php` as the single list of tenant tables.
  - Custom PHPStan rule (`Tests\PHPStan\Rules\TenantTableAccessRule`) against
    `DB::table()` / raw SQL on tenant tables and non-whitelisted
    `withoutGlobalScope(s)`, with rule tests.
  - Isolation test infrastructure: `assertTenantIsolation()`, a resource
    dataset, a route coverage test and a tenant-model inventory test.
  - `Identity` module: ULID tenant users (one tenant per user, global e-mail
    uniqueness), sign-in on the app host, TOTP 2FA with recovery codes
    (Filament MFA, encrypted, audited), invitations (signed, 72 h, single-use),
    re-authentication (password or 2FA code, 10 minutes), user deactivation.
  - `Access` module: spatie/laravel-permission 8 with teams (team = tenant),
    permission catalog and system roles seeders, permission-only policies,
    role changes with re-authentication, last-owner and no-escalation rules,
    sensitive-role notifications.
  - `PlatformAdmin` module: `platform_admins` and the `platform` guard on the
    admin host with mandatory 2FA, audited cross-host impersonation (read-only,
    30 minutes, banner), `axispay:create-platform-admin` command.
  - `Audit` module: append-only `audit_logs` (model guards and database
    triggers), redacted details, request metadata; records sign-ins, failed
    sign-ins, 2FA changes, invitations, role changes, tenant creation and status
    changes, impersonation and platform-context entries.
  - Filament 5.8 panels `admin` and `app`, themed from the design tokens
    (palettes built from `primitives.css`, self-hosted Jost, dark-mode bridge),
    EN/ES with a language switcher; resources: tenants, platform admins, audit
    log (admin); users, roles, audit log, profile (app).
  - `DevelopmentSeeder` (local only) and ADR-0030 to ADR-0033.
  - Security hardening after review (ADR-0034): shared `RoleGrantGuard` for
    role changes, invitations (re-checked on acceptance) and
    deactivation/reactivation; re-authentication for sensitive invitations and
    (de)activation; tenant row lock for owner invariants; per-host session
    cookies with a boot guard against a shared `SESSION_DOMAIN`; wider PHPStan
    tenancy rule; impersonation re-validates the platform admin on every
    request; per-account login throttling; per-tenant invitation throttling
    with audited refusals; local-only `axispay:dev-reset-2fa`.
- Phase 0 foundations (master plan section 27):
  - Tooling: Pest 5 (on PHPUnit 13), Larastan 3 at PHPStan level max, Pint with the
    Laravel preset plus `declare_strict_types` and strict comparison rules, and the
    Composer scripts `test`, `analyse`, `format`, `format:check` and `ci`.
  - Local MariaDB 11.8 LTS (`mariadb:11.8.9`) in `compose.yaml` with `utf8mb4` /
    `utf8mb4_uca1400_ai_ci`, strict `sql_mode`, UTC and InnoDB; `axispay` and
    `axispay_testing` databases; `axispay_app` and `axispay_migrator` users.
  - `mariadb` connection with explicit charset, collation, `sql_mode`, time zone,
    engine and optional TLS; `mariadb_migrator` connection for deploy-time migrations.
  - `app/Modules` structure with the `Shared` module: money value objects on
    brick/money (string parsing, minor units, HALF_UP rounding, DECIMAL(18,6)
    exchange rates), prefixed ULID identifiers, ULID model traits and schema
    macros for `ascii_bin` columns, API error envelope and `ApiErrorCode` catalog,
    `Request-Id` middleware.
  - Structured JSON log channel and secret/PII redaction on every log channel.
  - `sentry/sentry-laravel`, inert until `SENTRY_LARAVEL_DSN` is set, with an event
    scrubber that reuses the log redactor (Sentry or GlitchTip).
  - ADR-0001 to ADR-0024 from the master plan, and ADR-0025 to ADR-0029.
  - OpenAPI 3.1 skeleton (`docs/api/openapi.yaml`), `docs/development.md`, and a
    GitHub Actions CI workflow on PHP 8.4 and 8.5 with a MariaDB 11.8 service.

### Changed

- Typography (ADR-0042): Mukta replaces Jost as the text face (Blade pages and
  both Filament panels), and Geist Mono is the face for currency and numeric
  money data, exposed as the token `--font-numeric` / utility `font-numeric`.
  The `amount` utility (and so `<x-amount>` and `class="amount"` inputs) now
  sets the numeric face; Filament money columns use
  `->fontFamily(FontFamily::Mono)`, mapped to `--font-numeric` in the panel
  theme. `--font-mono` (code) is Geist Mono too. Both faces are self-hosted from
  `@fontsource/mukta` and `@fontsource/geist-mono` (latin subset; Mukta
  400/500/600/700 with 400 and 600 preloaded and a fontaine fallback, Geist
  Mono 400/600 without preload); `@fontsource/jost` is removed. Fonts are now
  loaded through the plugin's `local()` provider on the packages' WOFF2 files:
  with `fontsource()`, browsers downloaded each weight twice (woff2 preload
  unused, woff applied). The
  `/design-system` typography section shows both faces.
- Renamed the product to AxisPay (ADR-0037, supersedes ADR-0028): internal
  name `axispay` replaces the working name `paylink` in `config/axispay.php`,
  `AXISPAY_*` environment variables, `axispay:*` Artisan commands, session keys
  and cookies (`axispay_admin_session`, `axispay_app_session`), database names
  and users (`axispay`, `axispay_testing`, `axispay_app`, `axispay_migrator`),
  the compose project and the image (`axispay-entrypoint`,
  `axispay-healthcheck`). API keys use the `axp_test_` / `axp_live_` prefix.
- The public display name is configurable with `AXISPAY_DISPLAY_NAME`
  (default "AxisPay", read through `Brand::displayName()`) for pages, panels,
  the 2FA issuer, the mail sender and mail templates. `APP_NAME` is now a fixed
  internal value (`AxisPay`) because it drives the cache, Redis and session
  prefixes.
- pnpm is the only package manager: its version is pinned in
  `package.json` → `packageManager` (used by CI and the Dockerfile), and the
  `composer setup` script uses pnpm instead of npm.
- Production logging guidance: the container logs redacted JSON to stderr
  (`LOG_CHANNEL=stderr` with the JSON formatter) instead of rotated files.
- The default `users` migration now creates tenants and tenant users with ULID
  keys and DATETIME(6); `sessions.user_id` is a `char(26)` ULID.
- `App\Models\User` moved to `App\Modules\Identity\Models\User`.
- `<x-language-switcher>` accepts an optional `redirect` path.
- `.atl/` and Filament's published assets are gitignored; `filament:upgrade`
  runs on `post-autoload-dump`.
- Minimum PHP version raised from 8.3 to 8.4 (required by Pest 5; the plan prefers 8.4).
- Tests run against MariaDB instead of in-memory SQLite.
- `declare(strict_types=1);` added to every PHP file.
- `rules.md` now points to `docs/plans/master.md`.
