# ADR-0012: Platform fees on refunds and disputes

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-012

## Context

Refunds and disputes happen after a fee has been computed.

## Options considered

1. **Charge on gross, without reversal.**
2. **Charge on net, reversing on refunds.**

## Decision

Charge on gross (option 1): the fixed fee is not returned and the percentage is computed on the gross collected amount.

## Rationale

Common industry practice; simple and reproducible to compute; does not reopen closed periods; avoids abuse (charging and refunding to avoid the fee).

## Consequences

- Must be stated in the commercial terms and conditions.
- The versioned plan design allows a net-based variant per tenant in the future.
- **Monetary base:** the fee is computed on the amount actually collected and in its currency (MXN when a conversion happened). Reports never sum different currencies.
