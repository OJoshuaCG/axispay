# ADR-0009: USD to MXN conversion: card detection + explicit confirmation

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-009

## Context

Stripe documents that Stripe accounts in Mexico can only charge Mexican cards in MXN. A USD link from a merchant with a Mexican account cannot be charged to a Mexican card in USD. A foreign card can pay in USD to a Mexican account, and a US account does not have the restriction.

## Options considered

1. **Always charge in MXN when the account is Mexican.** Simple, but foreign payers pay in MXN.
2. **Detect the card country before confirming** (ConfirmationToken -> `payment_method_preview.card.country`) and convert only when it applies.
3. **Stripe Adaptive Pricing.** Stripe controls the exchange rate and charges the payer a conversion fee; oriented to Checkout Sessions. Discarded because the exchange rate is not under our control.

## Decision

Option 2, **optional per tenant**. Conversion applies if and only if connected account country = MX **and** card country = MX **and** link currency = USD **and** conversion is enabled for the tenant.

## Rationale

Protects both the payer and the merchant: no charge is sent before the payer sees and accepts the exact MXN amount, and Stripe declines are never used as a detection mechanism (a decline creates a failed attempt in the merchant account and affects its risk signals).

## Consequences

- Flow: the page shows the USD total and, when applicable, an informative MXN legend; on "Pay" the backend inspects the card country **before** any charge; if conversion applies, no charge is sent and the page shows a confirmation screen with the exact MXN amount, rate, source and date; only after explicit confirmation is the PaymentIntent updated to MXN and confirmed.
- If conversion is disabled and the restricted combination occurs: no charge, a clear message to the payer, and the event is recorded.
- Plan section 13 details the flow. **Apple Pay and Google Pay are disabled in the MVP**, because the wallet sheet shows the amount before the card country is known.
