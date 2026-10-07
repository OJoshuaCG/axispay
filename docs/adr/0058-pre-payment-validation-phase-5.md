# ADR-0058: Pre-payment validation in Phase 5: how answers are read, failures, the alert and removing the URL

- **Status:** Accepted (by the project owner, 2026-10-02); the 5-second total is amended by [ADR-0061](0061-validation-timeout-single-setting.md) (one setting, 30 s by default)
- **Date:** 2026-10-07
- **Source:** master plan sections 7.4, 7.6, 15.8 and 27 (Phase 5); [ADR-024](0024-pre-payment-validation.md), [ADR-0050](0050-linear-payment-flow-authorize-validate-capture.md) (authorize, validate, capture), [ADR-0057](0057-outgoing-webhooks-delivery-phase-5.md) (signature and protection of merchant URLs shared with the webhooks).

## Context

Phase 5 connects step 4 of the payment flow: after the card is authorized and before it is charged, the platform asks the merchant's server whether to go ahead. ADR-024 and section 15.8 of the plan fix the main rules: a signed call, 5 seconds in total, one retry only when the connection could not be opened, an answer of `approve` or `reject`, and a failure policy per merchant (`fail_closed` by default, or `fail_open`). A few details were left open and are decided here.

## Decision

### Reading the merchant's answer

| Part of the answer | What happens |
|---|---|
| HTTP status other than 200 (redirects included; they are never followed) | Failure; the merchant's policy applies. |
| More than 4 KB, not a JSON object, `decision` missing or not exactly `approve` or `reject` | Failure (invalid answer); the merchant's policy applies. |
| No `Content-Type: application/json` header | Accepted. "Test validation" shows it as a warning. |
| `reason_code` that does not follow the documented format | Ignored; the decision still counts. Shown as a warning in the test. |
| `payer_message` longer than 200 characters | Shortened to 200. Line breaks become spaces and invisible characters are removed; it is always shown as plain text. |
| `payer_message` that is not text, or `cancel_link` that is not true or false | Ignored (`cancel_link` counts as false). Shown as a warning in the test. |
| `payer_message` or `cancel_link` in an approval | Ignored: an approval never cancels a link. |
| Unknown fields | Ignored, as the plan says. |

Only the decision decides whether the answer is valid. A small mistake in an optional field must not stop a merchant's sales, and dropping it is always the safe side (no message, no cancellation).

### What the payer sees

A rejection shows the merchant's message, or the generic one when there is none. A failure under `fail_closed` shows the same generic message as a rejection without a message: the payer is not told that the merchant's server failed, only to contact the merchant. In both cases the card authorization is released and the link can be paid again, unless the merchant asked to cancel it.

### Cancelling the link

`cancel_link: true` in a rejection is kept with the decision, so it is applied by whoever releases the authorization, now or later (the payer's request, a background job, the reconciliation, or Stripe's notification that the authorization was released when our own request got no answer). The link is cancelled with the reason `rejected_by_merchant` in the same step that records the released authorization, so no other payer can start a new payment in between. If by then the link is no longer open for payment (it expired or was paid) or a newer payment of the link exists, it is left as it is.

### What counts as one validation

Every validation call is logged for 30 days with a sequence number per link, so a payer who retries after a declined card is validated again and the merchant can tell the calls apart. "Test validation" calls are logged too, marked as tests, and never count towards the failure alert.

### The failure alert

After 10 failures in a row the owners and the users with the webhooks permission receive an e-mail, and the panel shows an alert on the validation settings. While the failures continue, at most one e-mail is sent per hour. The first valid answer (an approval or a rejection) resets the count and removes the alert. Validation is never switched off by itself, as the plan requires.

### Removing the validation URL

A merchant can remove the validation URL of a mode at any time, even when the account is read-only. Links created while it existed keep asking for validation. Until a URL is configured again they are **not charged**, whatever the policy was: the platform cannot read a policy that no longer exists, and charging without the check the link promised is the unsafe side. The panel must warn about this before removing. New links can no longer ask for validation (`400 validation_endpoint_not_configured`) and default to no validation.

There is no "disabled" state separate from removing the URL. Links can already be created without validation, per link or by default.

### Security and privacy

- The validation URL has its own signing secret, different from the webhooks' secrets. It is shown once when the URL is first configured and after each rotation; the previous secret keeps signing for 24 hours after a rotation.
- Configuring, changing, rotating and removing need the webhooks permission and a recent re-authentication. Every change e-mails the owners and the users with that permission, and is written to the audit log with the URL's host only.
- The call carries the payer's e-mail and name, as the plan shows, and of the card only its brand and country. The logged copy of each call is stored encrypted and is deleted after 30 days.

## Consequences

- The 5 seconds include looking up the merchant's domain and, as for webhooks, the address called is the canonical form of the one that was checked (a domain with a final dot is refused). When the lookup uses up the time, the call counts as a timeout and the merchant's policy applies. A single lookup cannot be cut short by the platform (it lasts at most what the servers' name-resolution settings allow), so in the worst case the payer waits longer than 5 seconds; operations should keep the resolution timeout of the servers short.
- The payment's `pre_validation` block (in the outgoing payment events now, in the payments API in Phase 7) says whether the merchant approved, rejected, or failed and which policy was applied, so an integrator can review payments charged under `fail_open`.
- Integrator documentation must explain the answer rules above, the warnings shown by "Test validation", and that removing the URL stops the charges of links created with validation.
- Still open: the `payment_link.canceled` event (not yet part of the outgoing event catalog), the currency conversion data of the call (Phase 6) and the per-tenant metrics of section 15.8.7.
