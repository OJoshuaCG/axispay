# ADR-0048: Payment links API (Phase 3) — limits, API keys, idempotency and link rules

- **Status:** Accepted (owner decisions of 2026-09-26 for expiration, amounts and rate limits; the rest by project owner delegation). **Amended 2026-10-06** ([ADR-0063](0063-currency-conversion-tenant-fixed-rate-and-payment-settings.md), proposed): the maximum expiration is 60 days (it was 90), link rules 3 and 4 no longer refuse conversion for supported modes, and the tenant settings screen for expiration exists (see the notes under each section).
- **Date:** 2026-09-26
- **Source:** project owner decisions (2026-09-26) on open questions #5, #6 and #12; master plan sections 3 (ADR-006, ADR-007, ADR-013, ADR-015, ADR-020), 6, 7.2, 7.5, 7.8, 8, 9.1, 10.1–10.5, 11.1, 12.3.4, 17.3, 19.1, 21.3, 26.2 (cases 11, 12, 14, 15), 27 (Phase 3); [ADR-0031](0031-tenancy-enforcement.md), [ADR-0047](0047-stripe-connection-phase-2-and-api-key-reordering.md)

## Context

Phase 3 delivers API keys, API idempotency, the `payment_link` endpoints, the link state machine, the expiration job, API rate limiting and the panel screens for keys and links. Three values were open in the plan (section 29): link expiration, amount limits per currency and API rate limits. The owner fixed them on 2026-09-26, all configurable. Implementing the rest raised details the plan leaves open; they are recorded here as rules.

## Decision

### 1. Owner decisions (open questions #5, #6, #12)

| Topic | Rule | Who can change it |
|---|---|---|
| Link expiration | Default **7 days**; minimum **15 minutes**; maximum **60 days** (1,440 hours; **90 days until 2026-10-06**, lowered by the owner so every link layer shares one ceiling). | Platform configuration. A tenant may lower its default and its maximum, never above the platform maximum; a tenant value stored above the new maximum is clamped when read, and links already created keep their expiry. |
| Minimum amount | Stripe's documented minimum charge: **USD 0.50**, **MXN 10.00**. | Platform configuration (follows Stripe). |
| Maximum amount | Platform risk cap: **USD 10,000.00**, **MXN 200,000.00** per link. | Platform configuration. A tenant may lower it per currency, never raise it. |
| API rate limit | **100 requests per minute per API key**, in live and in test. | Platform configuration, separately per mode. |

- The minimums were checked on 2026-09-26 in Stripe's documentation ("Minimum and maximum charge amounts", docs.stripe.com/currencies): USD 0.50, MXN 10.
- **Known caveat (Stripe rule):** Stripe applies the minimum of the account's *settlement* currency after conversion. A USD link paid into a merchant account that settles in MXN must also reach MXN 10 once converted, so a USD link close to USD 0.50 may be refused by Stripe at charge time. We accept the link and let the charge report Stripe's refusal (Phase 4). The platform's own conversion check (`amount_below_minimum_after_conversion`) arrived with FX (ADR-0063).
- The plan suggested 50 requests per minute in test mode; the owner chose the same 100 for both modes. The plan does not suggest a separate lower limit for creation, so there is none.
- Tenant-level overrides exist for expiration and maximum amount (tenant settings); there is no tenant-level override of the rate limit yet. The expiration values (and the currency conversion settings) are edited in the tenant panel's "Payment settings" page since 2026-10-06 (ADR-0063); the amount caps still wait for the other tenant settings (Phase 8).

### 2. API keys (plan 10.2, ADR-015)

| Rule | Detail |
|---|---|
| Owner | The tenant, never a user. The creator is recorded for audit only. |
| Format | `axp_test_` or `axp_live_` followed by 43 base62 characters (32 random bytes). |
| Shown | **Once**, at creation, in a dialog that closes only with "I have copied the key". The full key exists only in the answer that opens that dialog; no later exchange between the browser and the server carries it. Afterwards only the first four characters of the secret and the last four are shown (`axp_live_a1b2…9xYz`). |
| Stored | SHA-256 of the full key (high-entropy value, not a password), compared in constant time. |
| Mode | Decided by the prefix. A key whose prefix names a mode other than the one it was stored for never authenticates. A test key never sees live data and the other way round: another mode's resource answers 404 (critical case 11). |
| Failures | Missing, malformed, unknown, revoked, expired or wrong-mode key, or a key of a tenant closed for longer than the read-only window: always the same `401 invalid_api_key`, without saying which. |
| Guessing | Failed authentications are counted per client IP: after 30 in a minute (configurable), every request from that IP answers `429 rate_limited` with `Retry-After` until the minute passes, valid keys included. Successful requests never count. |
| Scopes | Chosen at creation from the plan's catalog: `links:create`, `links:read`, `links:cancel`, `payments:read`, `refunds:create`, `refunds:read`, `events:read`. All seven can be granted now so keys need not be reissued when later endpoints arrive; only the `links:*` scopes have endpoints in Phase 3. A missing scope answers `403 insufficient_scope`. |
| Last use | Date and IP written at most once per minute per key. |
| Expiry | Keys can carry an expiry date (column and check exist); the panel does not offer it yet. |
| Revocation | Immediate and final; the next request with the key is `401`. Revoking a **live** key e-mails every owner, naming the key and who revoked it. |
| Who | `api_keys:manage`, with re-authentication (password or 2FA code, 10 minutes) to create or revoke. Both are denied during impersonation (read-only), enforced by the action itself. A `suspended` or `closed` tenant cannot create keys (read-only panel, plan 21.3) but **can still revoke** them: revoking only removes access, and a tenant in trouble must be able to cut off a leaked key. |
| Owner e-mail | Creating a **live** key e-mails every owner, naming the key and its creator, never the key. |
| Audit | `api_key.created` and `api_key.revoked` with name, scopes, mode and the last four characters. The visible prefix is not kept: the log redaction masks anything shaped like one of the platform's API keys (whatever the configured key prefix is), by design. |

The ApiKeys module owns the whole **API access layer**: keys, authentication, scopes, rate limiting and idempotency. Requests pass, in this order: key authentication (sets the tenant and mode), rate limit, scope, idempotency, then the endpoint. Rate limiting runs before the scope check, so refused requests still count.

### 3. Tenant status through the API (plan 10.2, 21.3)

| Tenant status | Read (GET) | Create a link | Cancel a link |
|---|---|---|---|
| `active`, `grace` | Yes | Yes | Yes |
| `pending_onboarding` | Yes | `409 gateway_not_ready` | Yes |
| `suspended` | Yes | `403 tenant_suspended` | Yes (plan 10.2 blocks creation only) |
| `closed`, first 30 days after closing | Yes | `403 tenant_suspended` | `403 tenant_suspended` (read-only) |
| `closed`, after 30 days | `401 invalid_api_key` | `401` | `401` |

The 30 days are configurable and count from the closing date.

### 4. Rate limiting (plan 10.1)

- Fixed one-minute window per API key. Every response to an authenticated key carries `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` (seconds until the window resets), errors included; answers to requests that did not authenticate do not.
- Over the limit: `429 rate_limited` with `Retry-After` in seconds. Each key has its own budget, even within one tenant.
- Known limitation: under truly simultaneous bursts a key may slightly exceed its limit.

### 5. Idempotency (plan 7.8, 10.3)

| Endpoint | `Idempotency-Key` |
|---|---|
| `POST /v1/payment_links` | Required (`400 idempotency_key_required`). |
| `POST /v1/payment_links/{id}/cancel` | Optional, honored when sent. |

Key: 1 to 255 characters from letters, digits, `_`, `-`, `:` and `.`; anything else is `400 parameter_invalid` with `param: Idempotency-Key`. Keys are scoped per tenant and mode: two tenants may use the same key.

| Situation (same tenant and mode, same key) | Answer |
|---|---|
| First use | The request runs; its answer is stored. |
| Same method, path and body, first request finished | The stored status and body, byte for byte, with `Idempotent-Replayed: true`. |
| Same key, different body, method or path | `422 idempotency_key_reused`. |
| First request still running | `409 idempotency_request_in_progress`. |
| First request answered 5xx or crashed | Nothing is stored; a retry runs again. |
| First request stopped without answering (worker died) | After 5 minutes the key is taken over by the next request. The 5 minutes are well above the longest a request can run (the web server stops requests at 60 seconds), so a slow request is never taken over while still working. |
| A request that lost its key that way finishes later | Its answer reaches its client but is not stored, and it cannot free or overwrite the new owner's claim; this is logged. |
| More than 24 hours later, same body | The link created the first time (no second link). |
| More than 24 hours later, different body | `422 idempotency_key_reused`: a key that created a link stays bound to that request for good. |

- "Same body" compares the JSON after sorting object keys, so key order and whitespace do not make a request different. A number and a string are different values, and so are an object and a list (`{"0": "a"}` is not `["a"]`). An empty body, `{}` and `[]` are the same request: all mean "no fields".
- 4xx answers are stored and replayed like successes for the whole 24 hours, including answers that depend on the moment, such as `409 gateway_not_ready` or `403 tenant_suspended` (plan 10.3 excludes only 5xx). Fixing or retrying a rejected request needs a new key.
- The race between concurrent requests is decided by a unique key in the database.
- A link keeps the idempotency key it was created with and the fingerprint of the request body. If the idempotency record is gone (purged after 24 hours or lost between creating the link and storing the answer), the same key with the same body returns that link instead of creating a second one, and with another body is `422 idempotency_key_reused`.
- Expired records are purged hourly.

### 6. Creating a link (plan 8.2, 10.5)

**Field rules.** The first failing field is reported with its code and `param`.

| Field | Rule | Error |
|---|---|---|
| any unknown field | Refused (catches typos such as `expire_in_hours`). | `parameter_invalid` |
| `amount` | Decimal string only; format per plan 8.2; within the platform and tenant limits. | `amount_must_be_string` (JSON number, case 14), `amount_invalid`, `amount_below_minimum`, `amount_above_maximum`, `parameter_missing` |
| `currency` | `USD` or `MXN`; lowercase is normalized. | `currency_not_supported`, `parameter_missing` |
| `description` | Required, 1–500 characters after trimming spaces. | `parameter_missing`, `parameter_invalid` |
| `metadata` | Object of up to 20 keys; keys 1–40 characters `A-Z a-z 0-9 _ -` (keys made only of digits, such as `"0"` or `"123"`, are valid and stay strings); values strings up to 500 characters; nothing nested. A JSON list is refused. Always returned as an object. | `metadata_invalid` |
| `client_reference_id` | Up to 200 characters. | `parameter_invalid` |
| `expires_in_hours` / `expires_at` | Mutually exclusive. Hours is a whole number; `expires_at` is ISO-8601 with a time zone and must be a real date and time (February 31, hour 24 or an offset beyond ±14:00 are refused, never rolled over). The result must be 15 minutes to the tenant's maximum from now. | `parameter_invalid`, `expiration_out_of_range` |
| `fx.mode`, `fx.rate` | Mode `none`, `banxico_fix` or `fixed`; `fixed` needs a rate (decimal string, up to 6 decimals, above zero); a rate without `fixed` is refused. | `parameter_missing`, `parameter_invalid` |
| `payer_fields` | Catalog fields of plan 19.1 only, each `hidden`, `optional` or `required`. | `payer_field_invalid` |
| `return_url` | Absolute http(s) URL, up to 2048 characters, no user or password. HTTPS required in live mode. The host must equal one of the tenant's allowed return domains (case-insensitive; subdomains are not implied). | `parameter_invalid`, `return_url_not_allowed` |
| `locale` | `es` or `en`; default: the tenant's checkout language. | `parameter_invalid` |
| `pre_payment_validation` | Boolean. | `parameter_invalid`, `validation_endpoint_not_configured` |

**Business rules**, in this order:

| # | Rule | Error |
|---|---|---|
| 1 | Tenant `active` or `grace` (plan 21.3). `suspended` and `closed` cannot create links; `pending_onboarding` has no gateway yet. | `403 tenant_suspended`; `409 gateway_not_ready` for `pending_onboarding` |
| 2 | A gateway connection **in the link's mode** that is `active` with charges enabled, read from our database (ADR-017). | `409 gateway_not_ready` |
| 3 | Conversion is refused on MXN links. | `fx_not_available` |
| 4 | Conversion needs the tenant to have enabled it. *(Amended 2026-10-06, ADR-0063: the platform switch is gone; supported modes are accepted. A converting link also needs a usable rate, `fx_rate_invalid`, and an amount that reaches the MXN minimum once converted, `amount_below_minimum_after_conversion`.)* | `fx_not_available` |
| 5 | Tenant maximum amount, if set. | `amount_above_maximum` |
| 6 | Expiration range with the tenant's limits. | `expiration_out_of_range` |
| 7 | Return URL domain and scheme. | `return_url_not_allowed` |
| 8 | Pre-payment validation needs a validation URL for the mode. Validation endpoints arrive in Phase 5, so asking for it is refused and links are stored without it. | `validation_endpoint_not_configured` |

- **Payer fields frozen on the link:** the link's override, else the tenant's setting, else the platform default of plan 19.1 (e-mail optional, everything else hidden). The example in plan 7.3 shows other defaults; section 19.1 is the rule and wins.
- **Public URL:** `https://<pay host>/l/<token>`; the token is 43 base62 characters from 32 random bytes, independent of the link ID (plan 11.1). The base URL can be changed for local development. The page behind it is Phase 4.
- Link creation (API and panel) and cancellation are audited (`payment_link.created`, `payment_link.canceled`), with the API key, the user or the system as actor. The cancellation reason is free text and may hold personal data, so it stays on the link only; the audit records whether there was one and its length.
- The response never contains gateway identifiers (ADR-019). `payment` is always null until payments exist (Phase 4).

### 7. Reading and listing (plan 10.1, 10.5)

- A wrong prefix, a bare ULID, an unknown ID, another tenant's link or another mode's link: all `404 resource_not_found`.
- Lists are newest first. `limit` 1–100 (default 20). Cursors are link IDs: `starting_after` pages to older links, `ending_before` to newer ones; sending both is refused. `has_more` tells whether more exist in the direction paged.
- Filters: `status`, `currency`, `client_reference_id`, `created[gte]`, `created[lte]`. Dates accept Unix seconds or a real ISO-8601 date-time with a time zone (impossible dates are `400 parameter_invalid`). Unknown query parameters are ignored.
- A suspended tenant keeps reading (ADR-013).

### 8. Link states (plan 9.1)

| From | To | In Phase 3 |
|---|---|---|
| `active` | `expired` | Applied by the expiration job and by a cancel that finds the link past its expiry. |
| `active` | `canceled` | Applied by cancel (API, panel, gateway disconnection). |
| `active` | `processing`, `paid` | Defined; refused until payment attempts drive them (Phase 4). |
| `processing` | `active`, `paid`, `expired` | Defined; refused until Phase 4. |
| `expired`, `canceled` | `paid` | Defined (a late successful payment wins, ADR-006); refused until Phase 4. |
| `processing` | `canceled` | Never allowed (`409 link_payment_in_progress`). |
| `paid` | anything | Never allowed: terminal. |

Every transition runs inside a transaction on a locked row. A transition outside the table is a program error, logged and refused.

**Cancel:**

| Link | Answer |
|---|---|
| `active` | Canceled, with the optional reason (up to 500 characters). |
| already `canceled` | `200` with the link unchanged (cancel is idempotent). |
| `paid` or `expired` | `409 link_not_cancelable`. |
| `processing` | `409 link_payment_in_progress`. |
| `active` but past its expiry | Marked `expired`, then `409 link_not_cancelable`. |

Cancel needs `links:cancel`; it is not on the re-authentication list of plan 17.3, so it asks for confirmation only. In the panel, a `suspended` or `closed` tenant can neither create nor cancel (read-only panel, plan 21.3, ADR-013), and neither can an impersonating admin; both refusals are enforced by the actions themselves, not only by hiding the buttons. Through the API a suspended tenant can still cancel: plan 10.2 blocks only creation for suspended tenants, and canceling stops collecting rather than starting it. A closed tenant's API is read-only (section 3).

**Expiration job:** runs every minute on one server. Each run expires up to 5,000 due links across all tenants and stops starting new work after 40 seconds; the next minute continues. A link that fails is skipped and retried the next minute, and a link with a payment in progress (`processing`) never expires.

**Gateway disconnection** (plan 12.3.4): when a connection becomes `disconnected`, the owners are e-mailed first, then every `active` link of that tenant and mode is canceled with reason `gateway_disconnected` (system actor), however many there are.

- The cancellation runs in the background after the disconnection is saved, and is retried (5 attempts over about 40 minutes) if it fails halfway; it only touches links that are still active, so a retry finishes the rest. If the tenant already reconnected in that mode when it runs, nothing is canceled.
- A safety net runs every fifteen minutes: any tenant mode that still has active links but no connection (other than disconnected ones) gets its links canceled the same way.
- A **restricted** connection (charges disabled by Stripe) does not cancel links: plan 12.3.4 keeps them, with a notice on the checkout (Phase 4). It only blocks new links.
- New links are refused until a connection can charge again. Creating a link re-checks the connection inside the same database transaction that stores the link, holding it against a concurrent disconnection: either the disconnection was saved first and the link is refused (`gateway_not_ready`), or the link is saved first and is then canceled with the others.

### 9. Tenant panel

| Screen | Permission | Notes |
|---|---|---|
| Payments → Payment links | `links:read` | Current mode only. Status badges, amounts in the numeric font, search by description or reference, filters by status and currency, empty state. |
| Create payment link | `links:create` | Same rules and messages as the API, shown on the field or as a notification, in English or Spanish. Refused for `suspended` and `closed` tenants and during impersonation. |
| Link detail | `links:read` | Every field, copyable URL and ID, payer fields, metadata. |
| Cancel link | `links:cancel` | Confirmation with an optional reason; only on active links. Refused for `suspended` and `closed` tenants and during impersonation. |
| Settings → API keys | `api_keys:manage` | Current mode only; create (re-authentication, shown once; refused for `suspended` and `closed` tenants), revoke (re-authentication; always allowed). |

### 10. Scope-bypass whitelist

The Phase 3 additions to the scope-bypass whitelist are listed in [ADR-0031](0031-tenancy-enforcement.md).

## Consequences

- Integrators must send an `Idempotency-Key` to create links, may rely on replays for 24 hours, and must use a new key after changing a rejected request.
- The minimum/maximum amounts, expiration limits and rate limits change in configuration without a release; tenants can only tighten amounts and expiration.
- Phases 4, 5 and 6 switch on what is stored but refused now: attempt-driven transitions, pre-payment validation and currency conversion.
- The closed-tenant rule of plan 21.3 for the API (read-only for 30 days, then `401`) applies now; the rest of the closed-tenant matrix (panel access after 30 days, canceling active links on closing) remains Phase 9.
