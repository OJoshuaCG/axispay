# ADR-0055: Test-mode API-key connections charge without Stripe account activation

- **Status:** Accepted (by the project owner, 2026-09-29). Amends plan sections 9.1 (link creation), 10.4 (`gateway_not_ready`), 10.5 (business validation 2) and 12.3.4 (restricted status) for one case only.
- **Date:** 2026-09-29
- **Source:** plan sections 9.1, 10.4, 10.5, 12.3.3 and 12.3.4; [ADR-0047](0047-stripe-connection-phase-2-and-api-key-reordering.md).

## Context

A tenant connected Stripe with the "use my API keys" method, using test-mode keys of a brand-new Stripe account that was not activated yet. Stripe describes the account with a single set of flags that reflects **live** activation: "charges enabled" is off and the account reports pending information, even though no specific item is listed. Stripe itself accepts test payments on such an account.

The platform read that flag as "this account cannot charge": it marked the connection as restricted, warned "Stripe paused payments on this account" and refused to create payment links. The tenant could not test anything until activating the account for real money, which Stripe does not require for testing.

## Options considered

1. **Keep the rule as is:** every connection needs Stripe's "charges enabled". Simple, but it blocks testing for a reason that does not apply to test payments.
2. **Ignore the flag for every test-mode connection:** also for connections made through the platform (create or connect with Stripe). Rejected: those test accounts can be completed with Stripe's test data, and a test-mode account that is not completed is a real signal that onboarding is unfinished.
3. **Ignore the flag only for test-mode API-key connections (chosen):** the only case where the tenant owns the account, the platform offers no assisted onboarding, and the flag describes something (live activation) that test payments do not need.

## Decision

For a connection made **with API keys** and **in test mode**, Stripe's "charges enabled" flag no longer decides whether the connection can charge:

- once the keys pass the same validation as before (key type, permissions, same account, allowed country), the connection becomes **active**, and payment links and the payment page work, even if the Stripe account is not activated;
- the platform keeps storing what Stripe reports (charges enabled, payouts enabled, pending requirements), unchanged; only the platform's own conclusion changes;
- the tenant is not told that payments are paused. The Stripe connection page shows an informational notice instead: the account is not activated, test payments work, and it must be activated in Stripe before connecting live keys. The pending requirements stay visible as information, not as a warning.

**Unchanged:**

- API-key connections in **live** mode still need Stripe's "charges enabled";
- connections made through the platform (create or connect with Stripe) still need it **in both modes**;
- key validation, permission checks, rejected or revoked keys, deauthorization and disconnection work as before.

The rule lives in one place and every check that decides whether a connection can charge (connecting, updating keys, the daily key check, Stripe's account updates, the "refresh" action, link creation and the payment page) uses it.

**Also in this change:** the requirements section of the Stripe connection page now lists the items Stripe is still verifying, apart from the items that are due. Before, they were not shown at all.

## Consequences

- A tenant can integrate and test end to end with test-mode API keys from day one, without activating the Stripe account.
- **Existing connections are corrected without the tenant doing anything.** A test-mode API-key connection stored as restricted only because the account is not activated becomes active the next time its account is read from Stripe: at the daily key check, on the next account update Stripe sends, or at once when a manager uses "Refresh status" on the Stripe connection page. No data correction is needed.
- The status change is audited like any other status change, and no "payments paused" e-mail is sent for this case.
- Risk: a tenant tests successfully and then assumes live payments will work. Mitigation: the notice on the Stripe connection page says the account must be activated before connecting live keys, and a live connection of a non-activated account stays restricted, with the usual warning.
