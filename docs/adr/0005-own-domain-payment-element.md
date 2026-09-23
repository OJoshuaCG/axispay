# ADR-0005: Links live on our domain; charges use PaymentIntent + Payment Element

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-005

## Context

The payment page can either redirect to a Stripe-hosted page or be our own page embedding Stripe's card fields.

## Options considered

1. **Redirect to Stripe Checkout (Checkout Session).** Pros: less work. Cons: branding is controlled by the connected account settings, sessions expire (maximum 24 hours), and inserting the conversion logic is hard.
2. **Own page with Stripe.js Payment Element, in deferred-intent mode + ConfirmationToken.** Pros: real white label; PCI scope stays minimal (SAQ A, card data lives in Stripe iframes); expiration is ours; the card country can be inspected before confirming (needed for FX). Cons: more own UI and handling of 3DS and errors.

## Decision

Own page with the Payment Element (option 2).

## Rationale

White label, control of expiration, and the ability to inspect the card country before charging (required by ADR-009), while keeping PCI scope minimal.

## Consequences

- Public URL `https://pay.<domain>/l/{public_token}`.
- **A link is never a Stripe object**: the link is ours and PaymentIntents are attempts associated with it.
