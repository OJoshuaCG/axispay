# AxisPay API integration guide

This guide is for developers of another system (a store, an ERP, a billing tool) who want to use the AxisPay public API to **create payment links** and follow what happens to them.

The exact contract is [`openapi.yaml`](openapi.yaml). This guide explains how to use it in practice. When the two differ, the OpenAPI file wins; please report the difference.

## Contents

1. [Overview and quick start](#1-overview-and-quick-start)
2. [Prerequisites and environment](#2-prerequisites-and-environment)
3. [Authentication, scopes and rate limit](#3-authentication-scopes-and-rate-limit)
4. [Conventions](#4-conventions)
5. [Errors](#5-errors)
6. [Payment links](#6-payment-links)
7. [Following the outcome: events and webhooks](#7-following-the-outcome-events-and-webhooks)
8. [Pre-payment validation callback](#8-pre-payment-validation-callback)
9. [Integration checklist and recommended patterns](#9-integration-checklist-and-recommended-patterns)
10. [Not available yet](#10-not-available-yet)

---

## 1. Overview and quick start

AxisPay lets a merchant ("the account") charge payers by sending them a **payment link**. Your system creates the link through the API, gives the link URL to the payer (by e-mail, chat, on screen), and the payer pays on a page hosted by AxisPay. You never handle card data.

The API is **server to server only**: call it from your backend. It has no CORS support and API keys must never reach a browser or a mobile app.

### The 5-minute path

1. **Get a key.** In the AxisPay panel, switch to **test mode**, open **Settings -> API keys** and create a key with the permissions `links:create` and `links:read` (add `events:read` if you want to read events). Copy it at once: it is shown only once.
2. **Create a link.**

   ```sh
   curl -X POST https://api.example.com/v1/payment_links \
     -H "Authorization: Bearer axp_test_xxxxxxxx" \
     -H "Idempotency-Key: order-A-1029-attempt-1" \
     -H "Content-Type: application/json" \
     -d '{"amount": "1500.00", "currency": "MXN", "description": "Order A-1029", "client_reference_id": "A-1029"}'
   ```

3. **Send the returned `url`** (`https://pay.<domain>/l/<token>`) to the payer. Store the returned `id` (`plink_...`) next to your order.
4. **Learn the result.** Either receive the `payment.succeeded` / `payment_link.paid` webhook on your server, or read the link again with `GET /v1/payment_links/{id}` and check `status` (`paid`). Use both in production, see [section 9](#9-integration-checklist-and-recommended-patterns).

Replace `https://api.example.com` with your AxisPay API host (see the next section).

---

## 2. Prerequisites and environment

### API host

All endpoints live under `/v1` on the API host of the platform:

```
https://api.<platform-domain>/v1
```

The platform domain is deployment-specific. This guide uses `https://api.example.com` as a placeholder: ask the AxisPay operator for the real host. (A local development installation uses `http://api.localhost:8000/v1`.) The payment page that the payer opens is served from a separate host (`https://pay.<platform-domain>`); you only receive its URL in the response.

### Test and live mode

Every account has two fully separate modes:

| | Test mode | Live mode |
|---|---|---|
| Key prefix | `axp_test_` | `axp_live_` |
| Money | No real money moves | Real charges |
| Data | Only test data | Only live data |

- The **key prefix selects the mode**. There is no mode parameter. A test key never reaches live resources and the other way round: a resource of the other mode answers `404`.
- Resources carry a `livemode` boolean (`false` for test).
- Test and live have separate payment links, events, webhook endpoints, webhook secrets and idempotency keys.

### Key format

`axp_test_<secret>` or `axp_live_<secret>`, where the secret is 43 characters. Treat the whole string as an opaque secret. The examples here use placeholders such as `axp_test_xxxxxxxx`.

### Keys: where to create and revoke them

In the tenant panel, under **Settings -> API keys** (the panel shows the mode selected in its top bar, so switch to the mode you need first).

- Creating or revoking a key needs the "manage API keys" permission and a recent password or 2FA confirmation.
- Choose the **permissions (scopes)** the integration needs and nothing more.
- The full key is **shown only once**, in a dialog, when you create it. Afterwards only the first and last characters are visible. If you lose it, create a new key and revoke the old one.
- Revoking is immediate and final: the next request with that key answers `401`.
- Creating or revoking a live key sends an e-mail to the account owners.
- Keys are owned by the account, not by a user.

### What the account needs before you can create links

| Requirement | If it is not met |
|---|---|
| The account status is **active** (or in its grace period). | `403 tenant_suspended` when the account is suspended or closed. |
| The account has a **Stripe connection in the same mode as the key that is able to charge**. For a test key with the "use my API keys" connection method, a Stripe account that is not activated yet is enough for test payments; live mode always needs a Stripe account that can accept charges. | `409 gateway_not_ready` (also while the account is still finishing onboarding). |
| For `return_url`: the host is in the account's allowed return domains. | `400 return_url_not_allowed`. |
| For `pre_payment_validation: true`: a validation URL is configured for this mode. | `400 validation_endpoint_not_configured`. |

Account status and the API:

| Account status | Read (GET) | Create a link | Cancel a link |
|---|---|---|---|
| Active / grace | Yes | Yes | Yes |
| Onboarding not finished | Yes | `409 gateway_not_ready` | Yes |
| Suspended | Yes | `403 tenant_suspended` | Yes |
| Closed, first 30 days | Yes | `403 tenant_suspended` | `403 tenant_suspended` |
| Closed, after 30 days | `401 invalid_api_key` | `401` | `401` |

---

## 3. Authentication, scopes and rate limit

### Header

Send the key as a bearer token on every request:

```
Authorization: Bearer axp_test_xxxxxxxx
```

A missing, malformed, unknown, revoked, expired or wrong-mode key (or a key of an account closed for more than 30 days) always answers the same `401 invalid_api_key`, without saying which of these it is. The response then carries `WWW-Authenticate: Bearer`.

After **30 failed authentications in one minute from the same IP address**, every request from that IP (even with a valid key) answers `429 rate_limited` with a `Retry-After` header until the minute passes. Do not retry in a loop with a bad key.

### Scopes

A key can do only what its scopes allow; a request outside them answers `403 insufficient_scope`.

| Scope | Allows |
|---|---|
| `links:create` | `POST /v1/payment_links` |
| `links:read` | `GET /v1/payment_links`, `GET /v1/payment_links/{id}` |
| `links:cancel` | `POST /v1/payment_links/{id}/cancel` |
| `payments:read` | `GET /v1/payments`, `GET /v1/payments/{id}` |
| `events:read` | `GET /v1/events`, `GET /v1/events/{id}` |

The panel may also offer `refunds:create` and `refunds:read`. They are accepted when creating a key so you will not have to reissue it later, but **no endpoint uses them yet** (see [section 10](#10-not-available-yet)).

### Rate limit

- **100 requests per minute per API key** (the same in test and live by default; the platform can change it). Each key has its own budget.
- Every response to an authenticated request, errors included, carries:

  | Header | Meaning |
  |---|---|
  | `RateLimit-Limit` | Requests allowed per minute for this key. |
  | `RateLimit-Remaining` | Requests left in the current one-minute window. |
  | `RateLimit-Reset` | Seconds until the window resets. |

- Over the limit the answer is `429 rate_limited` with `Retry-After` (seconds). Wait that long before retrying. Requests that did not authenticate (`401`, and the failed-authentication `429`) carry only `Retry-After` where it applies, not the `RateLimit-*` headers.
- Requests refused for other reasons (for example a missing scope) still count against the limit.

---

## 4. Conventions

- **JSON, UTF-8.** Send `Content-Type: application/json` on every request with a body (without it the request is refused with `400 parameter_invalid`). Unknown request fields are refused on create. **Ignore unknown fields in responses**: compatible fields may be added without changing the version.
- **Versioning.** The version is in the path (`/v1`).
- **Money is a decimal string plus an ISO currency, never a number.** Send `"1500.00"`, not `1500.00`. A JSON number is rejected with `amount_must_be_string`. Format: digits with at most 2 decimals (for USD and MXN), no sign, spaces, thousands separators or exponent, and an integer part of at most 12 digits (`^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$`). Responses return `amount` (string, always with the currency's decimals) and `amount_minor` (integer in minor units, for example `150000` for `1500.00`). Never use floating point for money in your code; keep amounts as strings or integers of minor units.
- **Currencies:** `USD` and `MXN`. Lowercase input is normalized.
- **Prefixed IDs.** IDs are opaque strings with a type prefix: `plink_` (payment link), `pay_` (payment), `re_` (refund), `evt_` (event). An ID with the wrong prefix, an unknown ID, or an ID of another account or mode answers `404 resource_not_found`. Do not parse or assume a length; store them as strings (up to 64 characters is a safe column size). `val_` identifies a pre-payment validation call.
- **Timestamps** are ISO-8601 in UTC with a trailing `Z`, for example `2026-09-24T02:11:10Z`.
- **`Request-Id`.** Every response has a `Request-Id` header (`req_...`) that also appears in AxisPay's logs; quote it in support requests. You may send your own `X-Request-Id` (1 to 128 characters from letters, digits, `.`, `_`, `:`, `-`, starting with a letter or digit); it is reused as the `Request-Id` when valid, otherwise a new one is generated. The error body also carries it.
- **Pagination (cursor).** List endpoints return:

  ```json
  { "object": "list", "data": [ ... ], "has_more": true }
  ```

  Lists are **newest first**. `limit` is 1 to 100 (default 20). To go to **older** items pass `starting_after=<id of the last item you received>`; to go to **newer** items pass `ending_before=<id of the first item>`. Do not send both. `has_more` tells whether more items exist in the direction you paged. Unknown query parameters are ignored; a malformed `limit` or cursor answers `400 parameter_invalid`.
- **Idempotency.** See the next subsection.

### Idempotency-Key

| Endpoint | Header |
|---|---|
| `POST /v1/payment_links` | **Required** (`400 idempotency_key_required` when missing). |
| `POST /v1/payment_links/{id}/cancel` | Optional, honored when sent. |

Format: 1 to 255 characters from `A-Z a-z 0-9 _ - : .`; anything else answers `400 parameter_invalid` with `param: Idempotency-Key`. Use a unique value per intended operation, for example your order number plus an attempt counter. Keys are scoped to your account and mode.

Behavior, for the same key:

| Situation | Answer |
|---|---|
| First use | The request runs and its answer is stored. |
| Same method, path and body, within 24 hours | The stored status and body again, with the header `Idempotent-Replayed: true`. This includes stored `4xx` answers. |
| Same key with a different body, method or path | `422 idempotency_key_reused`. |
| The first request is still running | `409 idempotency_request_in_progress`. Wait a moment and retry with the same key. |
| The first request ended with a `5xx` or crashed | Nothing is stored: retrying with the same key runs the request again. |
| A request left unfinished for 5 minutes | Its key is freed for the next request. |
| More than 24 hours later, same body, key that created a link | You get the link created the first time (never a second one). |
| More than 24 hours later, different body, key that created a link | `422 idempotency_key_reused`: a key that created a link stays bound to it. |

Rules of thumb:

- "Same body" compares the JSON ignoring key order and whitespace. A number and a string are different values. An empty body, `{}` and `[]` count as the same.
- **After a network timeout, retry with the same key and the same body.** You will either get the original answer or a replay: never a duplicate link.
- A `4xx` answer is replayed for 24 hours too, for example `409 gateway_not_ready`. After you fix the cause (or want to change the request), use a **new** key.

---

## 5. Errors

Every error uses the same envelope and an HTTP status that matches the family of the error:

```json
{
  "error": {
    "type": "invalid_request_error",
    "code": "currency_not_supported",
    "message": "The currency 'EUR' is not supported. Allowed currencies: USD, MXN.",
    "param": "currency",
    "request_id": "req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S"
  }
}
```

| Field | Meaning |
|---|---|
| `type` | `invalid_request_error`, `authentication_error`, `permission_error`, `rate_limit_error` or `api_error`. |
| `code` | Stable machine-readable code. **Branch on this**. New codes may be added: handle unknown ones by their HTTP status. |
| `message` | Human-readable explanation, in English. Do not parse it. |
| `param` | The request parameter the error refers to (for example `amount`, `payer_fields.email`, `Idempotency-Key`), or `null`. |
| `request_id` | The same value as the `Request-Id` header. |

When a request has several problems, the first failing field is reported.

### Error codes

| Code | HTTP | Meaning and what to do |
|---|---|---|
| `invalid_api_key` | 401 | Missing, invalid, revoked, expired or wrong-mode key, or the account was closed more than 30 days ago. Check the key and mode. |
| `insufficient_scope` | 403 | The key does not have the scope this endpoint needs. Create a key with the right permissions. |
| `tenant_suspended` | 403 | The account is suspended or closed and cannot create resources (a closed account is read-only for 30 days). Contact the merchant or the platform. |
| `gateway_not_ready` | 409 | The account has no Stripe connection in this mode that can charge (not connected, disconnected, restricted, or onboarding not finished). The merchant must fix its connection in the panel. Retry with a new `Idempotency-Key` afterwards. |
| `parameter_missing` | 400 | A required parameter is missing (`amount`, `currency`, `description`, `fx.mode`, ...). See `param`. |
| `parameter_invalid` | 400 | A parameter has an invalid value or type, an unknown parameter was sent, the body is not a JSON object, `Content-Type` is not JSON, or a query parameter or header is malformed. |
| `amount_must_be_string` | 400 | The amount was sent as a JSON number. Send a decimal string. |
| `amount_invalid` | 400 | The amount format is invalid for the currency (too many decimals, sign, separators, empty). |
| `amount_below_minimum` | 400 | Below the minimum charge: USD 0.50, MXN 10.00. |
| `amount_above_maximum` | 400 | Above the maximum: USD 10,000.00 or MXN 200,000.00 by default; the account may have lowered it. |
| `amount_below_minimum_after_conversion` | 400 | The link converts (see [Currency conversion](#currency-conversion-usd-links-and-mexican-cards)) and its amount, converted to MXN, is below the MXN minimum charge (MXN 10.00). |
| `currency_not_supported` | 400 | Only `USD` and `MXN` are supported. |
| `expiration_out_of_range` | 400 | The expiry is outside 15 minutes to the account's maximum (60 days at most) from now, or `expires_in_hours` is below 1. |
| `fx_not_available` | 400 | Currency conversion (`fx`) was requested for an MXN link, or the account has not enabled it. |
| `fx_rate_invalid` | 400 | A `fixed` conversion has no rate to use (send `fx.rate` or set the account's fixed rate), or the rate is more than 30 % away from the latest published exchange rate. |
| `metadata_invalid` | 400 | `metadata` breaks its limits (see section 6). |
| `return_url_not_allowed` | 400 | The `return_url` host is not in the account's allowed return domains, or it is not HTTPS in live mode. |
| `payer_field_invalid` | 400 | `payer_fields` has an unknown field or an invalid requirement. |
| `validation_endpoint_not_configured` | 400 | `pre_payment_validation: true` but no validation URL is configured for this mode. |
| `idempotency_key_required` | 400 | `Idempotency-Key` is missing on `POST /v1/payment_links`. |
| `idempotency_key_reused` | 422 | The key was already used with a different request (or, after 24 hours, with a different body for a key that created a link). Use a new key for a new request. |
| `idempotency_request_in_progress` | 409 | A request with the same key is still running. Retry shortly with the same key. |
| `resource_not_found` | 404 | No such resource for your account and mode, or the ID has the wrong prefix. |
| `link_not_cancelable` | 409 | The link is already `paid` or `expired`. |
| `link_payment_in_progress` | 409 | A payment is in progress on the link (`processing`); it cannot be canceled now. Try again later. |
| `refund_exceeds_available` | 422 | Reserved for refunds, which are not available yet. |
| `payment_not_refundable` | 409 | Reserved for refunds, which are not available yet. |
| `rate_limited` | 429 | Rate limit exceeded, or too many failed authentications from your IP. Wait `Retry-After` seconds. |
| `gateway_error` | 502 | The payment gateway returned an error. Retry later (with the same idempotency key). |
| `internal_error` | 500 | Unexpected error. Retry with the same idempotency key; if it persists, send the `request_id` to support. |
| `method_not_allowed` | 405 | The HTTP method is not allowed for this endpoint. |
| `service_unavailable` | 503 | The service is temporarily unavailable. Retry later. |

`5xx` answers are never stored for idempotency, so retrying with the same key is safe.

---

## 6. Payment links

A payment link is a **single-use, reopenable** request for one payment of a fixed amount: the payer opens it, enters a card, and pays. If a card is declined, the payer can try again on the same link until it is paid, canceled or expired. There is no endpoint to edit a link: cancel it and create a new one.

### Create: `POST /v1/payment_links`

Scope `links:create`. `Idempotency-Key` is required. Success: `201 Created` with the link.

#### Request body

Unknown fields are refused (`parameter_invalid`), which catches typos such as `expire_in_hours`.

| Field | Type | Required | Rules |
|---|---|---|---|
| `amount` | string | Yes | Decimal string, at most 2 decimals, for example `"1500.00"`. Minimum USD 0.50 / MXN 10.00. Maximum USD 10,000.00 / MXN 200,000.00 (the account may set a lower maximum). Errors: `amount_must_be_string`, `amount_invalid`, `amount_below_minimum`, `amount_above_maximum`. |
| `currency` | string | Yes | `USD` or `MXN` (lowercase is accepted). |
| `description` | string | Yes | 1 to 500 characters after trimming. Shown to the payer as plain text. |
| `metadata` | object | No | Your private key-value data, never shown to the payer. Up to 20 keys; keys 1 to 40 characters from `A-Z a-z 0-9 _ -` (digit-only keys such as `"0"` are valid); values are strings up to 500 characters; no nesting, numbers or booleans; a JSON list is refused. Always returned as an object. Do not store personal data or secrets here. |
| `client_reference_id` | string | No | Your own reference, for example the order number. 1 to 200 characters. Not required to be unique; you can filter the list by it. |
| `expires_in_hours` | integer | No | Whole hours, at least 1. Not together with `expires_at`. |
| `expires_at` | string | No | ISO-8601 date-time **with a time zone**, for example `2026-10-10T18:30:00Z`. Impossible dates (February 31) are refused. Not together with `expires_in_hours`. |
| `payer_fields` | object | No | What to ask the payer. Fields: `email`, `full_name`, `phone`, `company_name`, `billing_address`, `tax_id`, `notes`. Each is `hidden`, `optional` or `required`. Fields you omit use the account's setting, otherwise the platform default (e-mail optional, everything else hidden). Error: `payer_field_invalid`. |
| `return_url` | string (URL) | No | Where the payer can return after paying. Absolute `http(s)` URL of at most 2048 characters, without user or password. **HTTPS is required in live mode** and the **host must exactly match** one of the account's allowed return domains (subdomains are not implied). Error: `return_url_not_allowed`. |
| `locale` | string | No | `es` or `en`: language of the payment page. Default: the account's checkout language. |
| `pre_payment_validation` | boolean | No | `true` asks your server to approve each payment of this link (see [section 8](#8-pre-payment-validation-callback)); needs a validation URL for this mode (`validation_endpoint_not_configured` otherwise). `false` turns it off for this link. Omitted: the account's "use for new links" setting decides. Fixed when the link is created. |
| `fx` | object | No | Currency conversion of a USD link paid with a Mexican card, see [Currency conversion](#currency-conversion-usd-links-and-mexican-cards): `{ "mode": "none" \| "fixed" \| "banxico_fix", "rate": "20.000000" }` (`rate` only with `fixed`). Omit it to use the account's default. |

**Expiration.** If you send neither `expires_in_hours` nor `expires_at`, the account's default applies (7 days unless the account lowered it). The expiry must fall between **15 minutes** and the account's maximum (**60 days** at most; the account sets its own default and maximum, never above 60 days, in its payment settings) from now, otherwise `expiration_out_of_range`.

#### Minimal request

```json
{
  "amount": "1500.00",
  "currency": "MXN",
  "description": "Order A-1029"
}
```

#### Fuller request

```json
{
  "amount": "1500.00",
  "currency": "USD",
  "description": "Order #A-1029 - 2 items",
  "metadata": { "order_id": "A-1029", "customer_id": "C-77" },
  "client_reference_id": "A-1029",
  "expires_in_hours": 72,
  "payer_fields": { "email": "required", "phone": "optional" },
  "return_url": "https://shop.example.com/thanks?order=A-1029",
  "locale": "en"
}
```

#### Response (`201`)

```json
{
  "id": "plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S",
  "object": "payment_link",
  "livemode": false,
  "status": "active",
  "url": "https://pay.example.com/l/3fK9...",
  "amount": "1500.00",
  "amount_minor": 150000,
  "currency": "USD",
  "description": "Order #A-1029 - 2 items",
  "metadata": { "order_id": "A-1029", "customer_id": "C-77" },
  "client_reference_id": "A-1029",
  "fx": { "mode": "none", "rate": null },
  "payer_fields": { "email": "required", "phone": "optional" },
  "return_url": "https://shop.example.com/thanks?order=A-1029",
  "locale": "en",
  "pre_payment_validation": false,
  "expires_at": "2026-10-06T14:00:00Z",
  "paid_at": null,
  "canceled_at": null,
  "cancel_reason": null,
  "expired_at": null,
  "refund_status": "none",
  "dispute_status": "none",
  "open_count": 0,
  "first_opened_at": null,
  "payment": null,
  "created_at": "2026-10-03T14:00:00Z"
}
```

(The exact set of `payer_fields` returned is the full frozen set for the link; the example is shortened.)

Fields to know:

| Field | Notes |
|---|---|
| `id` | `plink_...`. Store it. |
| `url` | The public payment page. **Anyone who has it can pay**: share it only with the payer. |
| `status` | See the lifecycle below. |
| `expires_at` | When the link stops accepting payments. |
| `paid_at`, `canceled_at`, `expired_at`, `cancel_reason` | Set when the link reaches that state, otherwise `null`. |
| `open_count`, `first_opened_at` | How many times, and when first, the payer opened the page. |
| `refund_status`, `dispute_status` | `none` for now (refunds and disputes are not implemented yet). |
| `payment` | Always `null` for now. Read the payments of a link with `GET /v1/payments?payment_link={id}` ([section 6](#payments-get-v1payments)), or from the events (`payment_link.paid` and `payment.succeeded` carry it). |

#### `curl`

```sh
export AXISPAY_API_KEY="axp_test_xxxxxxxx"      # from your secret store, never in code

curl -sS -X POST https://api.example.com/v1/payment_links \
  -H "Authorization: Bearer $AXISPAY_API_KEY" \
  -H "Idempotency-Key: order-A-1029-attempt-1" \
  -H "Content-Type: application/json" \
  -d '{
        "amount": "1500.00",
        "currency": "MXN",
        "description": "Order A-1029",
        "client_reference_id": "A-1029",
        "metadata": {"order_id": "A-1029"},
        "expires_in_hours": 72,
        "return_url": "https://shop.example.com/thanks?order=A-1029"
      }'
```

#### PHP

```php
<?php
declare(strict_types=1);

$apiKey = getenv('AXISPAY_API_KEY');            // axp_test_xxxxxxxx
$idempotencyKey = 'order-A-1029-attempt-1';     // store it with the order, reuse it on retries

$body = json_encode([
    'amount' => '1500.00',                      // a string, never a float
    'currency' => 'MXN',
    'description' => 'Order A-1029',
    'client_reference_id' => 'A-1029',
], JSON_THROW_ON_ERROR);

$ch = curl_init('https://api.example.com/v1/payment_links');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$apiKey}",
        "Idempotency-Key: {$idempotencyKey}",
        'Content-Type: application/json',
    ],
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);

if ($status === 201) {
    $linkId = $data['id'];     // plink_...
    $payUrl = $data['url'];    // send this to the payer
} else {
    // $data['error']['code'], $data['error']['message'], $data['error']['request_id']
}
```

#### Node (fetch, Node 18+)

```js
const apiKey = process.env.AXISPAY_API_KEY; // axp_test_xxxxxxxx

const res = await fetch('https://api.example.com/v1/payment_links', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${apiKey}`,
    'Idempotency-Key': 'order-A-1029-attempt-1', // store it with the order, reuse it on retries
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    amount: '1500.00', // a string, never a number
    currency: 'MXN',
    description: 'Order A-1029',
    client_reference_id: 'A-1029',
  }),
});

const data = await res.json();

if (res.status === 201) {
  const { id, url } = data; // store id (plink_...), send url to the payer
} else {
  const { code, message, request_id } = data.error;
  // branch on code
}
```

#### Python (requests)

```python
import os
import requests

api_key = os.environ["AXISPAY_API_KEY"]  # axp_test_xxxxxxxx

response = requests.post(
    "https://api.example.com/v1/payment_links",
    headers={
        "Authorization": f"Bearer {api_key}",
        "Idempotency-Key": "order-A-1029-attempt-1",  # store it with the order, reuse it on retries
    },
    json={
        "amount": "1500.00",  # a string, never a float
        "currency": "MXN",
        "description": "Order A-1029",
        "client_reference_id": "A-1029",
    },
    timeout=30,
)

data = response.json()

if response.status_code == 201:
    link_id = data["id"]   # plink_...
    pay_url = data["url"]  # send this to the payer
else:
    error = data["error"]  # error["code"], error["message"], error["request_id"]
```

### List: `GET /v1/payment_links`

Scope `links:read`. Links of your account and mode, newest first.

| Query parameter | Description |
|---|---|
| `limit` | 1 to 100, default 20. |
| `starting_after` | A link ID: returns links created **before** it (older). |
| `ending_before` | A link ID: returns links created **after** it (newer). Not together with `starting_after`. |
| `status` | One of `active`, `processing`, `paid`, `expired`, `canceled`. |
| `currency` | `USD` or `MXN`. |
| `client_reference_id` | Exact match of your reference (1 to 200 characters). |
| `created[gte]`, `created[lte]` | Created at or after / at or before. Unix seconds or ISO-8601 with a time zone. Impossible dates answer `400 parameter_invalid`. |

```sh
curl -sS -G https://api.example.com/v1/payment_links \
  -H "Authorization: Bearer $AXISPAY_API_KEY" \
  --data-urlencode "client_reference_id=A-1029" \
  --data-urlencode "status=paid" \
  --data-urlencode "limit=20"
```

Response: `{ "object": "list", "data": [ <payment link>, ... ], "has_more": false }`. To walk through all pages, repeat the request with `starting_after` set to the `id` of the last element while `has_more` is `true`.

### Retrieve: `GET /v1/payment_links/{id}`

Scope `links:read`. Returns the link. Another account's or mode's link, an unknown ID or a wrong prefix answers `404 resource_not_found`.

```sh
curl -sS https://api.example.com/v1/payment_links/plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S \
  -H "Authorization: Bearer $AXISPAY_API_KEY"
```

### Cancel: `POST /v1/payment_links/{id}/cancel`

Scope `links:cancel`. `Idempotency-Key` is optional. The body is optional: `{ "reason": "..." }` (up to 500 characters; visible in the API and the panel, never to the payer).

```sh
curl -sS -X POST https://api.example.com/v1/payment_links/plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S/cancel \
  -H "Authorization: Bearer $AXISPAY_API_KEY" \
  -H "Idempotency-Key: cancel-A-1029" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Customer changed the order"}'
```

| Link state when you cancel | Answer |
|---|---|
| `active` | `200`, the link now `canceled` (with `canceled_at` and `cancel_reason`). |
| already `canceled` | `200`, the link unchanged. |
| `paid` or `expired` | `409 link_not_cancelable`. |
| `processing` (a payment is in progress) | `409 link_payment_in_progress`. |
| `active` but already past its expiry | The link is marked `expired` and the answer is `409 link_not_cancelable`. |

Canceling is still allowed while the account is suspended.

### Payments: `GET /v1/payments`

Scope `payments:read`. A **payment** is one attempt at the gateway for a link: a declined card does not create another one, a payer who comes back after a void or a rejection does. Use it to fetch back a payment before you act on an event (for example before you release goods, or to see whether an authorized payment is still waiting, was captured or was voided). Read only: it never changes anything.

`GET /v1/payments/{id}` returns one payment (`pay_...`). `GET /v1/payments` lists the payments of your account and mode, newest first, which is also the way to read **the payments of a link**: `GET /v1/payments?payment_link=plink_...`.

| Query parameter | Description |
|---|---|
| `limit` | 1 to 100, default 20. |
| `starting_after` | A payment ID (`pay_...`): payments created before it (older). |
| `ending_before` | A payment ID: payments created after it (newer). Not together with `starting_after`. |
| `payment_link` | Only the payments of this link (`plink_...`). A link of another account or mode lists nothing. |
| `status` | One of `requires_payment_method`, `requires_confirmation`, `requires_action`, `requires_capture`, `processing`, `succeeded`, `failed`, `canceled`. |
| `created[gte]`, `created[lte]` | Created at or after / at or before. Unix seconds or ISO-8601 with a time zone. |

```sh
curl -sS https://api.example.com/v1/payments/pay_01J8Z4Q6T4Y0V8KX2M1N5P7R9S \
  -H "Authorization: Bearer $AXISPAY_API_KEY"

curl -sS -G https://api.example.com/v1/payments \
  -H "Authorization: Bearer $AXISPAY_API_KEY" \
  --data-urlencode "payment_link=plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S"
```

```json
{
  "id": "pay_01J8Z4Q6T4Y0V8KX2M1N5P7R9S",
  "object": "payment",
  "livemode": true,
  "payment_link": "plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S",
  "client_reference_id": "ORDER-1029",
  "status": "succeeded",
  "amount": "1500.00",
  "amount_minor": 150000,
  "currency": "USD",
  "fx": null,
  "late_payment": false,
  "failure_count": 0,
  "failure": null,
  "pre_validation": { "outcome": "approved" },
  "captured_at": "2026-09-24T02:11:09Z",
  "created_at": "2026-09-24T02:10:40Z",
  "card": { "brand": "visa", "country": "MX" },
  "authorized_at": "2026-09-24T02:11:05Z",
  "canceled_at": null
}
```

- `status` tells where the payment is: `requires_capture` (authorized, waiting for the capture or for your pre-payment validation answer), `processing`, `succeeded` (captured), `canceled` (voided: the money will not be taken) or `failed` (closed after at least one declined card).
- `client_reference_id` is the reference of the link (`null` when it has none). `payment_link` is its `plink_` ID.
- `fx` is `null` when the payment was charged in the link's own currency. When a Mexican card paid a USD link it describes the conversion (see [Currency conversion](#currency-conversion-usd-links-and-mexican-cards)), and `amount` and `currency` are what was charged, in MXN: reconcile with both.
- `card` has the brand and country only; either can be `null` before a card was read. Never the number, the last digits or a fingerprint. No gateway identifier and no payer data is returned.
- `captured_at` is set once the payment succeeded, `authorized_at` once it was authorized and `canceled_at` once it was canceled; otherwise `null`.
- An ID with a wrong prefix, an unknown ID, another account's or another mode's payment answers `404 resource_not_found`.

### Currency conversion (USD links and Mexican cards)

A Stripe account in Mexico can only charge a card issued in Mexico in Mexican pesos. So when a **USD** link of a Mexican account is paid with a card issued in Mexico, the platform converts the link's total to **MXN**: `converted = round_half_up(amount x rate)`, rounded once, to the cent. Example: a link of `12.30` USD with a rate of `20` is charged `246.00` MXN. A card issued anywhere else pays the USD amount as it is, and an MXN link is never converted.

- **It is the account's setting**, edited in the panel's *Payment settings* page: conversion on or off, the mode, the account's fixed rate, the markup and the quote validity. With conversion off, a Mexican card cannot pay a USD link: the payer is told the merchant cannot charge that amount in USD, and nothing is charged.
- **Modes.** `fixed`: your own rate (the link's `fx.rate`, else the account's fixed rate); no markup is applied on top. `banxico_fix`: the FIX that Banxico publishes (kept by the platform, never fetched while a payer pays), plus the account's markup; if the newest FIX is more than 4 days old the conversion is blocked until a new one arrives. A link sent without `fx` takes the account's default mode when it is USD and conversion is on; `{ "mode": "none" }` opts a link out.
- **Create-time checks.** `fx_rate_invalid` (no rate to use, or a rate more than 30 % away from the latest published one) and `amount_below_minimum_after_conversion` (the converted amount is below MXN 10.00). In the link object, `fx.rate` is `null` when the link uses the account's own fixed rate.
- **The payer confirms first.** The page shows a legend with the MXN amount; when the card turns out to be Mexican, a confirmation step shows the original amount, the amount to be charged, the rate and where it comes from, and the markup if there is one. Nothing is charged until the payer presses "Pay $X MXN". If a Banxico quote expires and the new amount differs, the payer is asked again.
- **What you receive.** The payment (in `GET /v1/payments`, in every `payment.*` event, in the payment of `payment_link.paid` and in the pre-payment validation body) has `amount` and `currency` = what was charged (MXN) and an `fx` block:

```json
"amount": "246.00",
"currency": "MXN",
"fx": {
  "applied": true,
  "mode": "fixed",
  "source": "merchant",
  "rate": "20.000000",
  "rate_date": null,
  "markup_bps": 0,
  "effective_rate": "20.000000",
  "original_amount": "12.30",
  "original_currency": "USD"
}
```

`mode` is `fixed` or `banxico_fix`; `source` is `merchant` (a fixed rate) or `banxico_fix`; `rate` and `rate_date` are the fixed or published rate and, for Banxico, its publication date; `markup_bps` and `effective_rate` are the markup and the rate that produced the charge; `original_amount` and `original_currency` are the link's. **Reconcile with both amounts**: the link says USD, the payment charged MXN. In the pre-payment validation body `charge.amount` is already the MXN amount while `payment_link.amount` stays the link's.

### Status lifecycle

| Status | Meaning |
|---|---|
| `active` | The payer can open it and pay. |
| `processing` | A payment is in progress (for example waiting for the bank's verification). It cannot be canceled and does not expire while it lasts. If the payment fails, the link goes back to `active` (or to `expired` if its time ran out meanwhile). |
| `paid` | A payment succeeded. **Terminal.** |
| `expired` | `expires_at` passed without payment. |
| `canceled` | Canceled by you through the API, by the merchant in the panel, or by the platform. |

Possible transitions:

| From | To | When |
|---|---|---|
| `active` | `processing` | The payer confirmed a payment. |
| `active` | `paid` | The payment succeeded. |
| `active` | `expired` | `expires_at` passed (a background job checks every minute, so it can take up to about a minute). |
| `active` | `canceled` | Canceled. |
| `processing` | `paid` | The payment succeeded. |
| `processing` | `active` | The payment failed or was abandoned; the payer can try again. |
| `processing` | `expired` | The payment ended after the link's expiry. |
| `expired`, `canceled` | `paid` | A **late payment**: the payer's payment was already in flight and succeeded afterwards. The money was charged, so the link becomes `paid`; the events carry `late_payment: true`. Handle it. |
| `paid` | (none) | Terminal. |
| `processing` | `canceled` | Never allowed. |

`cancel_reason` tells why a link was canceled: the free text sent with the cancel request, or one of the reasons the platform records itself: `gateway_disconnected` (the account's Stripe connection was disconnected in this mode; every active link of that mode is canceled), `tenant_closed` (the account was closed) or `rejected_by_merchant` (your pre-payment validation rejected a payment with `cancel_link: true`).

### What the payer sees

- The payer opens the link `url` and sees a payment page in the link's `locale` with the merchant's branding, the description, the amount and currency, the fields requested by `payer_fields`, and the card form.
- A card issued in Mexico paying a USD link: a currency confirmation step with the exact MXN amount before anything is charged (see [Currency conversion](#currency-conversion-usd-links-and-mexican-cards)).
- After paying: a "Payment complete" page. If you set `return_url`, it shows a **"Return to <merchant>"** button pointing to it (the payer is not redirected automatically, and no data is appended to the URL: do not rely on the return as proof of payment, see [section 9](#9-integration-checklist-and-recommended-patterns)).
- Opening a `paid` link shows "This payment has already been made"; an `expired` link shows "This payment link has expired"; a `canceled` link shows "This payment link is no longer available".
- If the card is declined, the payer sees the error and can try again on the same link. Repeated declines are limited (card-testing protection): after too many attempts the page asks to wait or blocks the link for a while.
- If your pre-payment validation rejects a payment, the payer sees your `payer_message`, or a generic message asking to contact the merchant. The card is not charged.

---

## 7. Following the outcome: events and webhooks

You learn what happened to a link through **events**. Every event can be:

- **pushed** to your server as a signed webhook, and
- **pulled** through `GET /v1/events` for 30 days.

### Events API

Scope `events:read`.

- `GET /v1/events/{id}` returns one event, with exactly the body that was or will be sent by webhook, byte for byte the same in every delivery. Use it to confirm a webhook before acting on it (fetch-back) or to read an event whose delivery you missed.
- `GET /v1/events` lists the events of your account and mode, newest first. It includes every event, whether or not you have a webhook endpoint subscribed to it, **stored in the last 30 days**.

| Query parameter | Description |
|---|---|
| `limit` | 1 to 100, default 20. |
| `starting_after` | An event ID (`evt_...`): events stored before it. |
| `ending_before` | An event ID: events stored after it. Not together with `starting_after`. |
| `type` | Only events of this type (see the list below). An unknown type is refused with `400 parameter_invalid`. |
| `created[gte]`, `created[lte]` | Stored at or after / at or before. Unix seconds or ISO-8601 with a time zone. |

- The `ping` test event is not part of the history; retrieving it answers `404`. An event older than 30 days, of another account or mode, or with an unknown ID or wrong prefix also answers `404`.
- `created[...]` refers to when the event was stored, which can be slightly later than the `created_at` inside the body.
- After downtime, list from the last event you processed and handle what is missing, deduplicating by `id`.

```sh
curl -sS -G https://api.example.com/v1/events \
  -H "Authorization: Bearer $AXISPAY_API_KEY" \
  --data-urlencode "type=payment.succeeded" \
  --data-urlencode "limit=50"

curl -sS https://api.example.com/v1/events/evt_01J8Z5Q6T4Y0V8KX2M1N5P7R9S \
  -H "Authorization: Bearer $AXISPAY_API_KEY"
```

### Event body

Webhooks and the events API share one body:

```json
{
  "id": "evt_01J8Z5Q6T4Y0V8KX2M1N5P7R9S",
  "type": "payment.succeeded",
  "api_version": "v1",
  "livemode": true,
  "created_at": "2026-09-24T02:11:10Z",
  "data": {
    "object": {
      "id": "pay_01J8Z4Q6T4Y0V8KX2M1N5P7R9S",
      "object": "payment",
      "livemode": true,
      "payment_link": "plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S",
      "status": "succeeded",
      "amount": "1500.00",
      "amount_minor": 150000,
      "currency": "MXN",
      "late_payment": false,
      "failure_count": 0,
      "failure": null,
      "pre_validation": { "outcome": "approved" },
      "created_at": "2026-09-24T02:10:40Z"
    },
    "payment_link": { "id": "plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S", "object": "payment_link", "status": "paid" },
    "late_payment": false
  }
}
```

`data.object` is the main object of the event; the other keys of `data` depend on the type. Amounts are strings. The payment objects never contain gateway identifiers.

| Event type | `data.object` | Other keys in `data` |
|---|---|---|
| `payment_link.opened` | The payment link | `open_count`, `first_open` (boolean) |
| `payment_link.paid` | The payment link | `payment` (the payment), `late_payment` |
| `payment.processing` | The payment | none |
| `payment.succeeded` | The payment | `payment_link` (the link, summarized), `late_payment` |
| `payment.failed` | The payment | `failure_count`, `failure_code` |
| `payment.canceled` | The payment | `reason` |

The **payment** object has `id` (`pay_...`), `object: "payment"`, `livemode`, `payment_link` (the `plink_` ID), `status` (`requires_payment_method`, `requires_confirmation`, `requires_action`, `requires_capture`, `processing`, `succeeded`, `failed`, `canceled`), `amount`, `amount_minor`, `currency`, `late_payment`, `failure_count` (declined cards so far), `failure` (`null` or `{ "code": ... }` with one of `card_declined`, `insufficient_funds`, `expired_card`, `incorrect_card_details`, `authentication_failed`, `processing_error`; more codes may be added), `pre_validation` (`null`, or `{ "outcome": "approved" | "rejected" | "failed", "policy_applied": "fail_open" | "fail_closed" }` where `policy_applied` appears only with `failed`), `client_reference_id` (the reference of the link, `null` when it has none), `captured_at` (when the payment was captured, `null` until it succeeds), `fx` (`null` unless a currency conversion applied, see [Currency conversion](#currency-conversion-usd-links-and-mexican-cards)) and `created_at`. A payment is one attempt at the gateway; a declined card does not create another payment object per retry.

**`payment.canceled`** is sent every time an authorized payment (or a 3D Secure step) is released and will not be charged: the merchant's pre-payment validation rejected it or failed under `fail_closed`, the capture window elapsed, the link was closed or canceled meanwhile, the payer abandoned a 3D Secure step, or the gateway released it on its own (for example the authorization expired before the capture). `data.reason` says which: `merchant_rejected`, `validation_failed`, `capture_window_elapsed`, `link_closed`, `abandoned_action` or `gateway_canceled` (new reasons may be added). `data.object` is the payment, with `status` `canceled` (or `failed` when a card had been declined before) and the same `pay_...` ID as the pre-payment validation call of that attempt. It is sent once per payment, only for payments that had been authorized or were waiting for the payer's bank (not for a card form the payer simply left), and an endpoint that lists its events explicitly must add it to receive it. If you did something on approval (reserved stock, credited a balance), undo it when this event arrives, after confirming it with `GET /v1/payments/{id}` (`status: canceled`).

**Event types today**

| Sent today | Defined and subscribable but not sent yet |
|---|---|
| `payment_link.opened` (first open, then at most once every 30 minutes; link previewers do not count), `payment_link.paid`, `payment.processing`, `payment.succeeded`, `payment.failed`, `payment.canceled` | `payment_link.created`, `payment_link.expired`, `payment_link.canceled`, `refund.created`, `refund.succeeded`, `refund.failed`, `dispute.created`, `dispute.closed` |

A `ping` event (`{ "object": "ping", "message": "Test event." }`) is sent when you press "Send test event" in the panel. New event types may be added: ignore the ones you do not know.

Because `payment_link.expired` and `payment_link.canceled` are not sent yet, learn about those states by reading the link (`GET /v1/payment_links/{id}`) if you need them.

### Outgoing webhooks: summary

This is a summary; the full guide, with setup in the panel, the delivery log, troubleshooting and more code, is [`docs/guides/webhooks.md`](../guides/webhooks.md).

**Setting up an endpoint.** In the panel, **Settings -> Webhooks -> Add endpoint**, per mode (up to 5 endpoints per mode). Choose the events it receives and copy the signing secret (`whsec_...`), shown once. Each endpoint has its own secret.

**Endpoint requirements (and protection against requests to internal networks):**

- `https` only (plain `http` only in test mode when the platform allows it), port **443 or 8443**, at most 2048 characters, no user or password in the URL.
- A **domain name**, never an IP address; no `localhost`, `.local` or `.internal`. Every address the domain resolves to must be public (private, loopback, link-local, CGNAT, multicast and reserved ranges are refused). The check is repeated before every delivery.
- Redirects are **not followed** (a `3xx` is a failure).
- AxisPay cannot reach `localhost`: for local development use a tunnel (for example `cloudflared` or `ngrok`) in test mode.

**Request headers**

| Header | Value |
|---|---|
| `webhook-id` | The event ID (`evt_...`). Stable across retries: **deduplicate by it**. |
| `webhook-timestamp` | Seconds since 1970 when this attempt was signed (changes on every attempt). |
| `webhook-signature` | `v1,<base64 HMAC-SHA256>`; several signatures separated by spaces during a secret rotation. |
| `User-Agent` | `AxisPay-Webhooks/1.0` |

**Delivery rules**

- Answer with any **2xx** within **10 seconds** (5 seconds to connect). Anything else, redirects included, is a failure. Store the event, answer, and process in the background.
- Retries: immediately, then after 5 seconds, 5 minutes, 30 minutes, 2 hours, 5 hours, 10 hours and 10 hours (**8 attempts in about 27 hours**), then the delivery is abandoned. A destination blocked by the security rules is never retried. The body never changes between attempts.
- **At least once** and **no ordering guarantee**: the same event can arrive twice, and events can arrive out of order. Use the event's own time and the object's state, not arrival order.
- An endpoint failing for **5 days in a row** is disabled automatically (the account's managers are e-mailed). Missed events can be recovered with `GET /v1/events` for 30 days.
- After a secret rotation, the old secret keeps signing for 24 hours and both signatures are sent.

**Verifying the signature** ([Standard Webhooks](https://www.standardwebhooks.com/)). Prefer an official library of your language. By hand:

1. Read the **raw body** exactly as received (verify before parsing JSON; re-encoding changes the bytes).
2. Build the signed text: `{webhook-id}.{webhook-timestamp}.{raw body}`.
3. Compute HMAC-SHA256 with the secret (the part after `whsec_`, **base64-decoded**), base64-encode it, and compare in constant time with each `v1,...` signature in `webhook-signature`. One match is enough.
4. Reject timestamps more than 5 minutes away from your clock.

Node example:

```js
import crypto from 'node:crypto';

function verifyWebhook(secret, rawBody, headers, toleranceSeconds = 300) {
  const id = headers['webhook-id'];
  const timestamp = headers['webhook-timestamp'];

  if (!id || !/^\d+$/.test(timestamp ?? '') || Math.abs(Date.now() / 1000 - Number(timestamp)) > toleranceSeconds) {
    return false;
  }

  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64');
  const expected = crypto.createHmac('sha256', key).update(`${id}.${timestamp}.`).update(rawBody).digest();

  return (headers['webhook-signature'] ?? '').split(' ').some((candidate) => {
    const [version, signature] = candidate.split(',');
    if (version !== 'v1' || !signature) return false;
    const given = Buffer.from(signature, 'base64');
    return given.length === expected.length && crypto.timingSafeEqual(given, expected);
  });
}

// Express: express.raw keeps the exact bytes received (req.body is a Buffer).
app.post('/axispay/events', express.raw({ type: '*/*' }), (req, res) => {
  if (!verifyWebhook(process.env.AXISPAY_WEBHOOK_SECRET, req.body, req.headers)) {
    return res.sendStatus(400);
  }
  const event = JSON.parse(req.body.toString('utf8'));
  // 1. Deduplicate by req.headers['webhook-id']  2. Store the event  3. Answer 2xx  4. Process in the background
  res.sendStatus(200);
});
```

PHP and Python versions are in the [webhooks guide](../guides/webhooks.md#verifying-a-request).

---

## 8. Pre-payment validation callback

An optional, **synchronous** check that lets your system veto a payment: "may we charge this?" It is not a webhook: your answer decides whether the payment is charged.

- **When:** only for links with `pre_payment_validation` on, after the payer's card was authorized and before it is charged. The payer waits on a "Verifying your order" screen.
- **Setup:** the merchant configures **one validation URL per mode** in the panel (**Settings -> Pre-payment validation**) with its own signing secret, and chooses a failure policy. Without a configured URL you cannot create links with `pre_payment_validation: true`.
- **The call:** a signed `POST` (same Standard Webhooks signature as events, with the validation URL's own secret) with `webhook-id` = the call ID (`val_...`) and the extra header `x-axispay-kind: pre_payment_validation`. The body has `id`, `type: "payment.pre_validation"`, `livemode`, `test` (`true` for the panel's "Test validation" button), `created_at`, `attempt_number` (a payer who retries after a declined card is validated again) and `data` with `payment` (`id`: the `pay_...` ID of this payment attempt, the same in the immediate retry and in every other call about that attempt, different for each new attempt of the link), `payment_link` (`id`, `client_reference_id`, `metadata`, `description`, `amount`, `currency`, `expires_at`), `charge` (the exact `amount` and `currency`, already converted to MXN when `fx` says so, and `fx`: `{ "applied": false }` or the conversion applied, with the same fields as the payment's `fx`), `card` (`brand` and `country` only, never the number) and `payer` (e-mail and full name, when collected).
- **Your answer:** HTTP **200** with a JSON object of at most **4 KB**, **within 30 seconds in total** by default (2 seconds to connect; the platform operator can set the limit between 5 and 60 seconds, and the panel's help screen shows the value in force):

  ```json
  { "decision": "approve" }
  ```

  ```json
  { "decision": "reject", "reason_code": "out_of_stock", "payer_message": "One of the items is no longer available. Contact the store.", "cancel_link": false }
  ```

  Only `decision` (`approve` or `reject`) is mandatory. `reason_code` (lowercase letters, digits and `_`, up to 64), `payer_message` (plain text shown to the payer on a rejection, up to 200 characters) and `cancel_link` (with `true` on a rejection, the link is canceled with reason `rejected_by_merchant`) are optional; a mistake in an optional field only drops that field. Unknown fields are ignored.
- **Failures and policy.** No answer in time, a connection or TLS error, a status other than 200 (redirects are not followed), or an invalid answer is a failure. The merchant's policy applies: `fail_closed` (default) does **not** charge; `fail_open` charges and marks the payment `pre_validation.outcome = failed`, `policy_applied = fail_open` so you can review it. A connection that could not be opened is retried once at once; nothing is retried after your server received the request.
- **An approval does not guarantee the charge.** Confirm the sale only with `payment.succeeded` (or `GET /v1/events`). If you reserve stock when approving, release it if `payment.succeeded` does not arrive within a reasonable time (15 minutes is a good default).
- If the merchant removes the validation URL, links created with validation are **not charged** until a URL is configured again.

Full details, code examples for answering the call and the stock reservation pattern: [`docs/guides/webhooks.md`](../guides/webhooks.md#pre-payment-validation).

---

## 9. Integration checklist and recommended patterns

### Recommended pattern

1. **Create the link** when the payer is ready to pay. Use a deterministic `Idempotency-Key` per intended link (for example `order-<id>-attempt-<n>`), and put your order number in `client_reference_id` and, if useful, in `metadata`.
2. **Store the `plink_` ID** (and the `url`) with your order before you give the URL to the payer. If the request times out, retry with the same key and body: you get the same link.
3. **React to events, then fetch back.** On `payment.succeeded` or `payment_link.paid`:
   - verify the signature on the raw body;
   - deduplicate by `webhook-id`;
   - confirm the event with `GET /v1/events/{id}` and/or the link with `GET /v1/payment_links/{id}` (`status: paid`);
   - check that `amount`, `currency` and `client_reference_id` (or the `plink_` ID) match your order;
   - only then fulfill the order.
4. **Reconcile periodically.** A scheduled job should list events since the last one processed (`GET /v1/events`) and, for orders still waiting, read their links. This covers webhooks you missed (your downtime, an endpoint disabled after 5 days of failures).
5. **Handle edge cases:** `late_payment: true` (a payment that succeeded after the link expired or was canceled; the money was charged); a `payment.failed` followed later by a success (the payer retried); the same event delivered twice; events out of order.
6. **Do not trust the payer's return.** The "Return" button is only a convenience; it carries no proof. Fulfill from events or a fetch-back.
7. **Expired or canceled links:** create a new link (with a new idempotency key) instead of trying to reuse them.

### Do not

- Never put an API key in a browser, mobile app, repository, log or URL. Create one key per integration with the minimum scopes, and rotate (create new, deploy, revoke old) if it may have leaked.
- Never use floating point for money. Compare amounts as strings or `amount_minor` integers.
- Never reuse an `Idempotency-Key` for a different request.
- Never parse the `message` of an error; use `code`.
- Never act on a webhook without verifying its signature.

### Go-live checklist (test to live)

- [ ] The integration works end to end in **test mode** with a test key: create, pay with Stripe test cards, receive and verify the webhook, fetch back, cancel, expiry.
- [ ] Error paths tested: `gateway_not_ready`, `insufficient_scope`, `429` (honoring `Retry-After`), a replayed idempotent request (`Idempotent-Replayed: true`), and a declined card.
- [ ] A webhook endpoint (HTTPS, public domain, ports 443/8443) is configured **in live mode** and its live `whsec_` secret is stored (test and live secrets differ).
- [ ] The merchant's **live** Stripe connection is active and able to charge (live mode needs an activated Stripe account), and live return domains are allowed if you use `return_url` (HTTPS only).
- [ ] A **live key** (`axp_live_...`) with minimum scopes is created, stored in your secret manager, and your configuration switches key, base URL and webhook secret together.
- [ ] Reconciliation job and duplicate/out-of-order handling are in place.
- [ ] Logs record `Request-Id` and the `plink_`/`evt_` IDs, and never record keys or secrets.
- [ ] A first small real payment is made and verified before opening to customers.

---

## 10. Not available yet

These are **not implemented** in this version of the API. Do not build on them:

- **Refunds.** There is no refund endpoint, the `refunds:*` scopes have no endpoint, and the events `refund.created`, `refund.succeeded` and `refund.failed` are never sent. A link's `refund_status` is `none` for now. Refunds are planned (Phase 7 of the project plan).
- **Disputes.** The events `dispute.created` and `dispute.closed` are never sent and a link's `dispute_status` is `none` for now. Planned (Phase 7).
- **Payer data, last digits and refunds in the payment object.** `GET /v1/payments` is read only and shows the card's brand and country, not the payer, the last digits, refunds or disputes (they come with Phase 7). The `payment` field of a link is still always `null`: read the link's payments with `GET /v1/payments?payment_link=...`.
- **Link events `payment_link.created`, `payment_link.expired`, `payment_link.canceled`** are defined but not sent yet.
- **Editing a link.** There is no update endpoint; cancel and create a new link.
- Currencies other than `USD` and `MXN`.
