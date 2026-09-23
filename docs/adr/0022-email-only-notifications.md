# ADR-0022: Notifications: e-mail only, no interaction with payers

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-022

## Context

The platform needs to notify operators and tenants about operational events.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

The platform does not send e-mails to payers. Operational e-mails go to tenant users with the relevant permission and to superadmins, depending on the event (plan section 22). Stripe receipts to the payer are a tenant option (off by default).

## Rationale

The payer relationship belongs to the tenant.

## Consequences

- **Assumption to confirm** (open question 4): the original instruction was "send e-mail only to the platform admin"; it is interpreted as platform alerts to superadmins and operational tenant alerts to tenant users.
