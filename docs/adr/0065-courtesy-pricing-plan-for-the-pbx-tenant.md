# ADR-0065: The pbx tenant is on a courtesy (all-zero) plan; no pricing-plan engine exists yet

- **Status:** Proposed. Records the owner's decision of 2026-10-06 (the platform fee is absorbed for pbx and configurable per tenant) for the `cirox-payment-links` change (spec B8, task CRX-12). It needs the owner's acceptance.
- **Date:** 2026-10-09
- **Source:** master plan sections 7.1 (`tenant_pricing_plans`) and 21 (21.1 plans, 21.2 monthly usage report); owner decisions of 2026-10-06, points 2 and 3; [ADR-0063](0063-currency-conversion-tenant-fixed-rate-and-payment-settings.md).

## Context

The owner wants the platform fee absorbed for the pbx tenant, with a per-tenant setting for other tenants that will pay. An early idea was to give pbx "infinite credits" (1,000,000). The plan has no credits model: it prices tenants with `tenant_pricing_plans` (versioned by validity; any combination of subscription, fixed fee and percentage is valid, "or everything at zero (courtesy tenant)", section 21.1) and bills them outside the system from the monthly usage report (21.2). Verified in the code on 2026-10-09: there is no `tenant_pricing_plans` table or model, no usage report, no fee calculation, and the platform takes no application fee on the Stripe charges (`rg application_fee app` finds nothing). Nothing charges any tenant today.

## Decision

1. **pbx is, and stays, on a courtesy plan: every component at zero** (no subscription, no fixed fee, no percentage, no minimum or maximum). Today that is the system's behavior for every tenant, because no fee engine exists, so **nothing is seeded, flagged or configured for pbx**: a row or a flag in a place that nothing reads would be a false guarantee.
2. **When section 21 is implemented**, the migration that creates `tenant_pricing_plans` also creates the first version for the pbx tenant (`effective_from` the day the table lands, `effective_to` null, all amounts null or zero, `notes` pointing to this ADR), and the usage report of that tenant lists the volume with zero fees. A tenant without a plan must never be billed by default: absence of a plan means zero, never an error and never a platform default fee. Changing pbx later is a new version, never an edit of the current one (21.1).
3. **Limits are unchanged and independent of the plan**: USD 0.50 to 10,000.00 and MXN 10.00 to 200,000.00 per payment (`axispay.currencies`; a tenant may lower its maximum, never raise it). The courtesy plan does not raise them: the platform risk cap is the owner's decision of 2026-09-26 and pbx pre-checks and explains the over-limit case on its side.
4. **No credits model.** A prepaid credits ledger is only built if a prepaid commercial model is chosen later; it is not needed to absorb a fee.

## Consequences

- No code, migration or seed changes now; the decision is a requirement on the future pricing work, so it cannot be forgotten when section 21 lands.
- The risk to watch: if the platform ever adds a fee path (for example a Stripe application fee) before `tenant_pricing_plans`, pbx would be charged by accident. Any such change must read the plan first and treat a missing plan as zero.
- Other tenants will get paid plans when section 21 exists; this ADR does not define them.
