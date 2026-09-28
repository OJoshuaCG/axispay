# ADR-0052: A temporary switch to turn off the bot check (Turnstile)

- **Status:** Accepted (by the project owner, 2026-09-28)
- **Date:** 2026-09-28
- **Source:** master plan section 11.7 (rule 3); [ADR-0051](0051-checkout-and-card-payments-phase-4.md), section 6 (card-testing protection).

## Context

After a card is declined on a payment link, the payment page asks the payer to pass a bot check (Cloudflare Turnstile) before trying again (ADR-0051). The check needs a Cloudflare account and its keys, and production refuses to start without them.

The project owner does not have a Cloudflare account yet and wants to deploy and take payments in the meantime.

## Decision

The owner chose to allow turning the bot check off with a deployment setting, **until a Cloudflare account exists**.

- **On** (the default): everything works as ADR-0051 describes, including the refusal to start in production without the keys.
- **Off:** the payment page never asks for the bot check, never shows it and never contacts Cloudflare, and the keys are not needed in any environment. Nothing is logged on each payment; the platform's health check (the doctor) shows a warning, never an error, so the operator sees that it is off.

**What still protects the payment page while it is off** (unchanged):

- a link pauses after 5 payment tries in 15 minutes (for 30 minutes);
- one client address may try at most 10 payments per hour across all links;
- cards Stripe does not recognize are limited per link and client and per client network;
- a link that receives 10 declines within 24 hours is blocked for 24 hours, and the merchant is notified and can lift the block;
- the payer never learns why a card was declined, and Stripe's own fraud screening (Radar) keeps working.

## Risk accepted

Without the bot check, an automated script can try stolen cards on a public link up to those limits: about 5 tries per link every 45 minutes and 10 per address per hour, and more with many addresses. Declines above normal levels can make Stripe flag or restrict the merchant's Stripe account. The 24-hour block after 10 declines stops a sustained attack on one link, but not one spread over many links.

## Consequences

- The platform can go live before a Cloudflare account exists.
- This is **temporary**: once the owner has a Cloudflare account, the keys are set and the switch is turned back on (or removed), restoring ADR-0051 as written.
- Operators see the state in the health check; the deployment guides describe the setting.
