# Webhooks and pre-payment validation: merchant guide

This guide is for merchants and their developers. It explains the two ways AxisPay talks to your server during a payment, how to set them up in the panel, how to check that a request really comes from us, and what to do when something fails.

Source of truth: master plan sections 15.1 to 15.8 (Spanish), [ADR-0057](../adr/0057-outgoing-webhooks-delivery-phase-5.md) (event delivery) and [ADR-0058](../adr/0058-pre-payment-validation-phase-5.md) (pre-payment validation) and [ADR-0060](../adr/0060-events-api-event-history.md) (event history in the API).

## Two different calls

| | Pre-payment validation (the "start" call) | Events (the "end" calls, webhooks) |
|---|---|---|
| What it is | A question: "may we charge this payment?" | A notice: "this happened" |
| When | During the payment, after the card is authorized and before it is charged | After something changed: a payment succeeded, a card was declined, a link was paid... |
| Does your answer matter? | Yes. Your answer decides whether the payment is charged | Only as a receipt: any 2xx status means "received" |
| Timing | Synchronous: the payer waits, at most 30 seconds by default (see [Timeouts and failures](#timeouts-and-failures)) | Asynchronous: sent in the background, retried for about 27 hours |
| How many | One URL per mode (test and live) | Up to 5 endpoints per mode, each with the events it wants |
| Optional? | Yes. Without it, payments are charged without asking you | Yes |

Both calls are signed the same way (see [Verifying the signature](#verifying-the-signature)), but each has its own secret: the validation secret never signs events, and each event endpoint has a secret of its own.

Test mode and live mode are fully separate. Endpoints, the validation URL, secrets and logs of one mode never mix with the other. The panel always shows the mode selected in its top bar.

## Where they fit in a payment

1. The payer opens your payment link, fills in the requested fields and enters a card.
2. The card is **authorized**: the bank reserves the amount, including any bank verification (3D Secure). Nothing is charged yet.
3. If the link uses pre-payment validation, we **ask your server**. The payer sees "Verifying your order…" meanwhile.
4. If you approve (or you do not use validation), the payment is **charged** (captured). If you reject, the reservation on the card is released and the payer sees your message.
5. Events are sent to your endpoints: `payment.succeeded` and `payment_link.paid` after a charge, `payment.failed` after each declined card.

A payer who retries after a declined card goes through steps 2 and 3 again, so you can receive more than one validation for the same link; each one carries its sequence number.

## Event endpoints (webhooks)

### Setting one up

In the panel: **Settings → Webhooks → Add endpoint** (permission "manage webhooks").

1. Enter the URL of your server. It must use `https://` and port 443 or 8443, a domain name (not an IP address), and it cannot point to a private network.
2. Add an optional description, for example "Order system".
3. Choose **Send every event** or pick the events you use. "Every event" also includes events added to the catalog later.
4. Confirm your password if asked (sensitive actions ask for it again every 10 minutes).
5. Copy the **signing secret** (`whsec_…`) shown in the dialog and store it on your server. It is shown once. You can reveal it later from the endpoint's page, after confirming your password; revealing it is recorded in the audit log.

Adding, changing, deleting an endpoint or rotating its secret sends an e-mail to the account owners and to the users who manage webhooks.

### The events

| Event | When it is sent | Sent today |
|---|---|---|
| `payment_link.opened` | The first time a link is opened, then at most once every 30 minutes | Yes |
| `payment_link.paid` | A link was paid (includes the payment) | Yes |
| `payment.processing` | A payment is being processed (rare with cards) | Yes |
| `payment.succeeded` | A payment was charged; includes whether it was a late payment and the result of the pre-payment validation | Yes |
| `payment.failed` | A card was declined; includes how many declines so far and a generic code | Yes |
| `payment_link.created`, `payment_link.expired`, `payment_link.canceled` | A link was created, expired or was canceled | Later phases |
| `refund.created`, `refund.succeeded`, `refund.failed` | Refunds | Phase 7 |
| `dispute.created`, `dispute.closed` | Disputes | Phase 7 |
| `ping` | The test event sent from the panel | Only from the test button |

You can subscribe to the events of later phases now; they start arriving when those features ship.

Each event has an ID (`evt_…`), a type, the mode, the time it happened and the object it is about, in the same shape as the public API. The body of an event never changes: every retry sends exactly the same bytes.

### What your server must do

- Answer with any **2xx** status as soon as the request is stored. Anything else, redirects included, counts as a failure. We read at most 2 KB of your answer.
- Answer **within 10 seconds** (5 seconds to open the connection).
- **Deduplicate by the event ID** (the `webhook-id` header). Delivery is "at least once": the same event can arrive more than once.
- **Do not rely on the order** of events. Use the time in the event and the state of the object.
- Before releasing goods, you can confirm an event by reading it from the API (fetch-back): `GET /v1/events/{id}` with the event ID returns exactly the body you received. See [Event history](#event-history-in-the-api).

### Event history in the API

Every event is stored when it happens, whether or not an endpoint was subscribed, and can be read for **30 days** with an API key that has the `events:read` scope (set when the key is created in the panel):

- `GET /v1/events/{id}` returns one event: exactly the body that was or will be sent to your endpoints, byte for byte the same in every retry and resend. Use it to confirm a webhook before acting on it, or to read an event whose delivery you missed.
- `GET /v1/events` lists events, newest first, with the same cursor pagination as the other lists (`limit`, `starting_after`, `ending_before`, and `has_more` in the answer). Filter with `type` (one event type) and `created[gte]` / `created[lte]`. After downtime, list from the last event you processed and handle what is missing, deduplicating by event ID.
- The test event (`ping`) is not part of the history. Test and live keys only see the events of their own mode, and an event of another account answers `404`.

The full contract is in [`docs/api/openapi.yaml`](../api/openapi.yaml).

### Retries, resends and automatic disabling

- A failed delivery is retried automatically: immediately, then after 5 seconds, 5 minutes, 30 minutes, 2 hours, 5 hours, 10 hours and 10 hours (8 attempts in about 27 hours). After that it is **abandoned**.
- A destination blocked by our security rules (for example a domain that now resolves to a private address) is never retried.
- The endpoint's page shows the **delivery log** of the last 30 days: event, attempt, status, HTTP code, time taken, error and the start of your answer. Filter it by status.
- **Resend** sends the same event again, once, with the same ID and body. It does not restart the automatic retries. The endpoint must be enabled.
- If every delivery to an endpoint fails for **5 days in a row**, the endpoint is **disabled** and the managers get an e-mail. The list shows "Failing since…" as soon as the failures start.
- **Disable** stops all sending (pending retries are dropped). **Enable** asks for your password, checks the URL again and only sends events created from then on; resend the missed ones from the log if you need them.

### Rotating the secret

**Rotate secret** creates a new secret and shows it once. The old one keeps working for **24 hours**: during that time every request carries both signatures, so you can update your server without losing events. Rotating again within those 24 hours drops the oldest secret.

## Pre-payment validation

### Setting it up

In the panel: **Settings → Pre-payment validation → Configure** (permission "manage webhooks"). One URL per mode.

- **Validation URL**: the same URL rules as for events.
- **If your server fails**, choose one policy:
  - **Do not charge (recommended)**: when your server does not answer in time, answers with an error or sends an invalid answer, the payment is not charged; the card reservation is released and the payer is asked to contact you. Nothing is ever charged without your approval, but an outage of your server stops your sales.
  - **Charge anyway**: in the same situations the payment is charged and marked "validation failed, charged" in the panel, the API and the `payment.succeeded` event. Sales continue during an outage, but you must review those payments yourself and refund them if needed.
- **Use it for new links by default**: whether links use validation when the API request does not say. Each link can also ask for it or not when it is created through the API; asking for it in a mode without a validation URL is refused.

The first time you save, a signing secret of its own is shown once. Rotating it works as for events (24 hours of overlap).

### What we send

A signed `POST` with the same signature headers as the events and an extra header that says it is a pre-payment validation. The body identifies the call (`val_…`), the mode, the time, the sequence number of this validation for the link, and:

- the payment: the ID of this payment attempt (`data.payment.id`, `pay_…`). It is the same in the immediate retry after a connection failure and in every other call about that attempt, and different for each new attempt of the link (a payer who retries after a declined card). Use it, with `data.payment_link.id`, to recognize a repeated call and to match the attempt later with the events and the payment you read from the API;
- the link: its ID, your reference (`client_reference_id`), your metadata, its description, amount, currency and expiry; the mode is the `livemode` of the body;
- the charge: the exact amount and currency about to be charged;
- the card: only its brand and country (never the number);
- the payer: the details the payer entered, such as the e-mail and name.

### What your server must answer

HTTP status **200**, a JSON object, at most **4 KB**, within the **time limit** in total (**30 seconds** by default; see [Timeouts and failures](#timeouts-and-failures)).

| Field | Required | Rules |
|---|---|---|
| `decision` | Yes | Exactly `approve` or `reject`. Anything else makes the answer invalid. |
| `reason_code` | No | Your own code for a rejection: lowercase letters, digits and underscores, up to 64 characters. Shown in your panel. If it does not follow the format it is ignored, but the decision still counts. |
| `payer_message` | No | Plain text shown to the payer on a rejection, up to 200 characters (longer texts are shortened; line breaks become spaces). Without it the payer sees a generic "The merchant could not confirm this operation" message. |
| `cancel_link` | No | `true` or `false` (default `false`). With `true` on a rejection, the link is canceled so it cannot be paid again. Ignored on an approval. |

- Only `decision` decides whether the answer is valid. A mistake in an optional field drops that field and the call still counts; "Test validation" shows it as a warning.
- Unknown fields are ignored.
- A missing `Content-Type: application/json` header is accepted and shown as a warning in the test.

### Timeouts and failures

- We wait 2 seconds to connect and **30 seconds in total** by default. The operator of the platform sets this limit with one setting (`AXISPAY_VALIDATION_TIMEOUT_SECONDS`, from 5 to 60 seconds; ADR-0061), and the "How it works" help of the validation settings page shows the value in force. Answer well before it: the payer is waiting.
- If the connection could not be opened at all, we retry once, immediately. We never retry after your server received the request, because it may already have processed it.
- These are failures, and your policy applies: no answer in time, a connection or TLS error, any status other than 200 (redirects are not followed), an answer that is not valid, and a destination blocked by our security rules.
- After **10 failures in a row**, the managers get an e-mail (at most one per hour) and the settings page shows a red alert. Validation is **never switched off by itself**. The first valid answer clears the alert.

### Guarantees and good practice

- **An approval does not guarantee the charge.** After you approve, the charge can still fail. Confirm the sale only with the `payment.succeeded` event or by reading the payment from the API.
- If you reserve stock when you approve, release it if `payment.succeeded` does not arrive within a reasonable time; 15 minutes is a good default. See [Reserving stock safely](#reserving-stock-safely).
- Expect several validations for one link when the payer retries; tell them apart by their sequence number and ID.

### Reserving stock safely

An approval is a promise to let the payment go ahead, not proof that it was paid: the charge can still fail after you approve. A safe pattern for stock:

1. When you **approve**, reserve the stock with an expiry of about **15 minutes**, and remember which payment link (and validation call ID) the reservation belongs to.
2. When `payment.succeeded` (or `payment_link.paid`) arrives for that link, turn the reservation into a sale. Check the event against your order first (amount, currency, your `client_reference_id`).
3. If `payment.succeeded` does not arrive before the expiry, release the stock. A payer who retries after a declined card is validated again and gets a new reservation; treat a second validation of the same link as a renewal of the same reservation, not as a second item.
4. When you **reject**, reserve nothing: the card reservation is released and nothing is charged.
5. A late event is possible after the expiry. If `payment.succeeded` arrives after you released the stock, reserve it again if you can, or refund the payment.

Never ship goods on the approval alone, and never on the payer returning to your site: use the event, or confirm it by reading it from the [event history](#event-history-in-the-api).

### Removing it

**Remove** asks for your password. New links can no longer use validation. Links already created with validation are **not charged** until a validation URL is configured again in that mode, whatever your policy was: we cannot apply a policy that no longer exists, and charging without the check the link promised would be unsafe. The call log is kept for 30 days.

### What you see in the panel

- The settings page lists the **recent calls** of the last 30 days: time, link, result, reason or failure, HTTP code and time taken. Test calls are marked as tests.
- Each **link's detail** lists its validations: result, reason, failure and policy applied, whether it was charged and the time taken.
- **Payments** (in the Payments menu) shows each payment's validation result and, in its timeline, every validation call.

## Verifying the signature

Every request we send (events and validations) follows the [Standard Webhooks](https://www.standardwebhooks.com/) specification. Use one of its official libraries, available for most languages, instead of writing your own check. With the secret from the panel, the library does all of the following:

1. It reads three headers: `webhook-id` (the event or call ID), `webhook-timestamp` (seconds since 1970) and `webhook-signature`.
2. It rebuilds the signed text: the ID, a dot, the timestamp, a dot, and the **raw body exactly as received**. Parse the JSON only after verifying; re-encoding it changes the bytes and breaks the signature.
3. It computes an HMAC-SHA256 with the secret (the part after `whsec_`, base64-decoded) and compares it, in constant time, with the signatures in the header. The header can hold more than one signature separated by spaces (during a secret rotation); one match is enough.
4. It rejects timestamps more than 5 minutes away from the current time, which stops replayed requests.

Then deduplicate by `webhook-id`. The pre-payment validation is authenticated in the other direction too: your server is reached over a verified TLS connection that we open.

## Code examples

Examples for a receiving server in PHP, Node and Python. They are small on purpose: adapt the error handling and storage to your application. Examples use the secret shown in the panel (`whsec_…`), kept in an environment variable and never in the code.

### Verifying a request

Use an official Standard Webhooks library when your language has one (for Node, `new Webhook(secret).verify(rawBody, headers)` from `standardwebhooks`; for Python, `Webhook(secret).verify(raw_body, headers)` from the package of the same name). If you prefer to verify by hand, these functions do exactly what [Verifying the signature](#verifying-the-signature) describes. The same function verifies events and validation calls; only the secret differs.

**PHP**

```php
function verifyWebhook(string $secret, string $rawBody, array $headers, int $toleranceSeconds = 300): bool
{
    $id = $headers['webhook-id'] ?? '';
    $timestamp = $headers['webhook-timestamp'] ?? '';

    if ($id === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $toleranceSeconds) {
        return false;
    }

    $key = base64_decode(substr($secret, strlen('whsec_')), true);

    if ($key === false) {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$rawBody}", $key, true));

    foreach (explode(' ', $headers['webhook-signature'] ?? '') as $candidate) {
        [$version, $signature] = array_pad(explode(',', $candidate, 2), 2, '');

        if ($version === 'v1' && hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

// In your controller or front script: the raw body, before any JSON parsing.
$rawBody = file_get_contents('php://input');
$headers = [
    'webhook-id' => $_SERVER['HTTP_WEBHOOK_ID'] ?? '',
    'webhook-timestamp' => $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '',
    'webhook-signature' => $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '',
];

if (! verifyWebhook(getenv('AXISPAY_WEBHOOK_SECRET'), $rawBody, $headers)) {
    http_response_code(400);
    exit;
}

$event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
// Deduplicate by $headers['webhook-id'], store the event, answer 2xx, process in the background.
http_response_code(200);
```

**Node (Express)**

```js
import crypto from 'node:crypto';
import express from 'express';

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

const app = express();

// express.raw keeps the body as the exact bytes received (req.body is a Buffer).
app.post('/axispay/events', express.raw({ type: '*/*' }), (req, res) => {
  if (!verifyWebhook(process.env.AXISPAY_WEBHOOK_SECRET, req.body, req.headers)) {
    return res.sendStatus(400);
  }

  const event = JSON.parse(req.body.toString('utf8'));
  // Deduplicate by req.headers['webhook-id'], store the event, answer 2xx, process in the background.
  res.sendStatus(200);
});
```

**Python (Flask)**

```python
import base64
import hashlib
import hmac
import json
import os
import time

from flask import Flask, request

app = Flask(__name__)


def verify_webhook(secret: str, raw_body: bytes, headers, tolerance_seconds: int = 300) -> bool:
    msg_id = headers.get("webhook-id", "")
    timestamp = headers.get("webhook-timestamp", "")

    if not msg_id or not timestamp.isdigit() or abs(time.time() - int(timestamp)) > tolerance_seconds:
        return False

    key = base64.b64decode(secret.removeprefix("whsec_"))
    signed = f"{msg_id}.{timestamp}.".encode() + raw_body
    expected = base64.b64encode(hmac.new(key, signed, hashlib.sha256).digest()).decode()

    for candidate in headers.get("webhook-signature", "").split(" "):
        version, _, signature = candidate.partition(",")
        if version == "v1" and hmac.compare_digest(expected, signature):
            return True

    return False


@app.post("/axispay/events")
def receive_event():
    raw_body = request.get_data()  # the exact bytes, before any JSON parsing

    if not verify_webhook(os.environ["AXISPAY_WEBHOOK_SECRET"], raw_body, request.headers):
        return "", 400

    event = json.loads(raw_body)
    # Deduplicate by request.headers["webhook-id"], store the event, answer 2xx, process in the background.
    return "", 200
```

### Answering a pre-payment validation

The call arrives with the same signature headers (signed with the validation URL's own secret) and the header `x-axispay-kind: pre_payment_validation`. Each example verifies the signature (with the function above), recognizes the test call of the panel's button, checks the amount against your own order, reserves the stock for 15 minutes and answers within a second or two. Answer `200` with a JSON body; anything else counts as a failure and your failure policy applies.

The examples use your own functions `findOrder`, `reserveStock`; they stand for your application's logic. `reserveStock` should be idempotent for the same link, because a payer who retries is validated again.

**PHP**

```php
$rawBody = file_get_contents('php://input');
$headers = [
    'webhook-id' => $_SERVER['HTTP_WEBHOOK_ID'] ?? '',
    'webhook-timestamp' => $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '',
    'webhook-signature' => $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '',
];

if (! verifyWebhook(getenv('AXISPAY_VALIDATION_SECRET'), $rawBody, $headers)) {
    http_response_code(400);
    exit;
}

$call = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
header('Content-Type: application/json');

if ($call['test'] ?? false) {
    // "Test validation" button: no real order behind it.
    echo json_encode(['decision' => 'approve']);
    exit;
}

$link = $call['data']['payment_link'];
$charge = $call['data']['charge'];
$order = findOrder($link['client_reference_id']);

if ($order === null || $order->amount !== $charge['amount'] || $order->currency !== $charge['currency']) {
    echo json_encode(['decision' => 'reject', 'reason_code' => 'order_mismatch', 'payer_message' => 'We could not confirm this order. Please contact the store.']);
    exit;
}

if (! reserveStock($order, expiresInMinutes: 15)) {
    echo json_encode(['decision' => 'reject', 'reason_code' => 'out_of_stock', 'payer_message' => 'One of the items is no longer available.', 'cancel_link' => true]);
    exit;
}

echo json_encode(['decision' => 'approve']);
```

**Node (Express)**

```js
app.post('/axispay/validation', express.raw({ type: '*/*' }), async (req, res) => {
  if (!verifyWebhook(process.env.AXISPAY_VALIDATION_SECRET, req.body, req.headers)) {
    return res.sendStatus(400);
  }

  const call = JSON.parse(req.body.toString('utf8'));

  if (call.test) {
    // "Test validation" button: no real order behind it.
    return res.json({ decision: 'approve' });
  }

  const { payment_link: link, charge } = call.data;
  const order = await findOrder(link.client_reference_id);

  if (!order || order.amount !== charge.amount || order.currency !== charge.currency) {
    return res.json({
      decision: 'reject',
      reason_code: 'order_mismatch',
      payer_message: 'We could not confirm this order. Please contact the store.',
    });
  }

  if (!(await reserveStock(order, { expiresInMinutes: 15 }))) {
    return res.json({
      decision: 'reject',
      reason_code: 'out_of_stock',
      payer_message: 'One of the items is no longer available.',
      cancel_link: true,
    });
  }

  res.json({ decision: 'approve' });
});
```

**Python (Flask)**

```python
@app.post("/axispay/validation")
def pre_payment_validation():
    raw_body = request.get_data()

    if not verify_webhook(os.environ["AXISPAY_VALIDATION_SECRET"], raw_body, request.headers):
        return "", 400

    call = json.loads(raw_body)

    if call.get("test"):
        # "Test validation" button: no real order behind it.
        return {"decision": "approve"}

    link = call["data"]["payment_link"]
    charge = call["data"]["charge"]
    order = find_order(link["client_reference_id"])

    if order is None or order.amount != charge["amount"] or order.currency != charge["currency"]:
        return {
            "decision": "reject",
            "reason_code": "order_mismatch",
            "payer_message": "We could not confirm this order. Please contact the store.",
        }

    if not reserve_stock(order, expires_in_minutes=15):
        return {
            "decision": "reject",
            "reason_code": "out_of_stock",
            "payer_message": "One of the items is no longer available.",
            "cancel_link": True,
        }

    return {"decision": "approve"}
```

Flask turns a returned dictionary into a JSON answer with status 200 and the right content type.

## The test buttons

- **Send test event** (endpoint list or detail): sends a signed `ping` now and shows whether it was delivered, the HTTP status, the time taken, the error if any and the start of your answer. It works on a disabled endpoint too, so you can check a fix before enabling it. It is never retried and never changes the endpoint's health.
- **Test validation** (settings page): sends a signed example payload marked `"test": true` and shows the HTTP status, the time taken, the decision read, whether the format is valid, the errors and warnings, and the start of your answer. It charges nothing and does not count towards the failure alert. Make your server recognize `"test": true` and answer without touching real orders.

## Testing on your own computer

Our servers cannot reach `localhost`. Expose your local server over a public `https` address with a tunnel, and use that address in **test mode**:

```sh
cloudflared tunnel --url http://localhost:3000
ngrok http 3000
```

Paste the `https://…` address the tunnel prints (plus your path) as the endpoint or validation URL, then use the test buttons. Tunnel addresses usually change on every start: update the URL in the panel each time.

For platform operators running AxisPay locally or on a test server: plain `http` URLs are refused by default. `AXISPAY_WEBHOOKS_ALLOW_HTTP_IN_TEST=true` allows them in test mode only (ports 80 and 8080); live mode always requires `https`.

## Troubleshooting

| What you see | Likely cause | What to do |
|---|---|---|
| "The URL must start with https://" when saving | An `http://` URL | Use `https`, or a tunnel for local tests |
| "The domain resolves to a private or reserved address" | The domain points to an internal network, or to `localhost` | Use a public address, for example through a tunnel |
| "Use a domain name, not an IP address" | The URL has an IP address | Use a domain name |
| Test event: "Your server did not answer with a 2xx status" | Your endpoint returned 3xx, 4xx or 5xx | Answer 2xx; check the path, your authentication middleware and redirects (`http`→`https`, trailing slashes) |
| Test event: "No answer within 10 seconds" | Your server does slow work before answering | Store the event, answer 2xx, then process it in the background |
| Your server rejects every signature | The body was parsed or re-encoded before verifying, the wrong secret is used (each endpoint and the validation have their own), or the server clock is off | Verify on the raw body, copy the right secret, sync the clock |
| Test validation: "The answer has no decision field" or "must be approve or reject" | Wrong field name or value | Answer `{"decision": "approve"}` or `"reject"`, exactly |
| Test validation: "The answer must have HTTP status 200" | Your server answered 201, 204 or a redirect | Answer 200 with a JSON body |
| Test validation: "No answer within 30 seconds" (the limit in force) | Your checks are too slow | Keep the validation fast; do slow work after the payment |
| Red alert "Your pre-payment validation is failing" | 10 failed calls in a row | Fix your server and run **Test validation**; the alert clears with the next valid answer |
| Payers of links with validation cannot pay after you removed the URL | Links created with validation are not charged without a URL | Configure the URL again, or create new links without validation |
| "Failing since…" on an endpoint | Every delivery fails | Open the delivery log, fix the cause, send a test event, then resend what you missed |
| Endpoint "Disabled by failures" | 5 days of continuous failures | Fix it, send a test event, enable it, resend missed events from the log |
| The same event arrives twice | Delivery is at least once | Deduplicate by `webhook-id` |
