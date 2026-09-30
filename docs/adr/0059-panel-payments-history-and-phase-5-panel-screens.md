# ADR-0059: Payment history in the tenant panel brought forward from Phase 8, and the Phase 5 panel screens

- **Status:** Proposed (Phase 5, slice C)
- **Date:** 2026-10-08
- **Source:** master plan sections 15.1, 15.8.1, 15.8.7, 17.1, 20.2 and 27 (Phases 5 and 8); [ADR-0057](0057-outgoing-webhooks-delivery-phase-5.md), [ADR-0058](0058-pre-payment-validation-phase-5.md), [ADR-0049](0049-panel-money-formatting-and-tenant-timezone.md) (money and time zone in the panel).

## Context

Phase 5 needs panel screens for the webhook endpoints, the pre-payment validation settings and the validation calls of each link (plan 15.1, 15.8.1, 15.8.7). With validation in place, a merchant also needs to see, across links, which payments were approved, rejected or charged under the "charge anyway" policy. Until now the panel only shows payments inside each link's detail; a payment history belongs to the tenant dashboard of Phase 8 (plan 20.2).

## Decision

### A payment history now, read-only

The tenant panel gets **Payments** (menu group Payments, next to Payment links), brought forward from Phase 8. It needs the `payments:read` permission and only reads.

- **What a row is.** One row per payment attempt, not per successful payment. An attempt is one payment at the gateway: a declined card does not create a new one, and it is what the API calls a `payment` (`pay_…`, plan 10.6). Listing only successful payments would hide exactly what validation adds (rejected and released payments) and the declines a merchant is asked about. The status filter therefore shows all statuses by default.
- **Columns:** date, link (with its reference; the detail links to the link), amount and currency (the panel's money format), status, card brand and last four digits, and the pre-payment validation result.
- **Filters and search:** status, currency, validation result and a date range (whole days in the tenant's time zone). Search by the link's description, the start of its reference, or a payment ID. Newest first.
- **Detail:** the amount as the heading, status, link, card and country, validation result (with a note when the payment was charged after a failed validation), declines, late payment and review notes, the payment ID and the gateway payment ID (for support; it never leaves the panel, ADR-019), and a **timeline**: started, each decline, card authorized, each validation call, charged or authorization released (and why), and every event recorded for the payment with the webhook event it became.
- **Payer data is not shown.** The payer's details are stored encrypted and the panel does not show them anywhere yet; they cannot be searched either. Showing them, with the retention and purge rules, stays with the payer fields work of Phase 8.
- The metrics, charts, CSV export and the rest of the Phase 8 dashboard are not part of this.

### Webhook endpoints (Settings → Webhooks)

List of the current mode's endpoints (URL, description, events, status and "failing since"), create and edit (URL, description, every event or a chosen list), the secret shown once in a dialog that only closes with "I have copied the secret", reveal and rotate, disable and enable, delete, and "Send test event" with its result (delivered or not, HTTP status, time, error, start of the answer). The endpoint's page shows the delivery log with a status filter, the details of each attempt and "Resend" (not for tests or pending attempts). Creating, editing, rotating, revealing, enabling and deleting ask for the password when the re-authentication window is closed.

### Pre-payment validation (Settings → Pre-payment validation)

One page per mode: the URL, the failure policy with the plain explanation of both options, the default for new links, the secret shown once and its rotation, removing it (the dialog warns that links created with validation are not charged until a URL is configured again), "Test validation" with its full result (HTTP status, time, decision, whether the format is valid, errors, warnings and the start of the answer), the red alert while the endpoint is failing, and the recent calls of the last 30 days.

### Validation calls on the link's detail

Each link's detail lists its validation calls (result, reason, failure and policy applied, charged or not, time taken). Like the recent calls and the validation steps of the payment timeline, they are shown only to users with `webhooks:manage`, the permission that reads the validation log (plan 17.1). Users with only `payments:read` see the validation result of each payment.

## Consequences

- Phase 8 builds its dashboard next to this history instead of creating it; the payer details and the CSV export are added there.
- The Spanish copy of the webhook screens and e-mails uses the panels' formal register (*usted*), as the rest of the panels do.
- Integrator documentation for both calls is in [docs/guides/webhooks.md](../guides/webhooks.md).
