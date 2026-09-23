# ADR-0016: Queues and async work: Laravel database driver

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-016

## Context

The platform needs background jobs (webhook delivery, provider events, rollups).

## Options considered

1. Redis + Horizon.
2. SQS.
3. **Database queue.**

## Decision

Laravel `database` driver on MariaDB (uses `SKIP LOCKED`).

## Rationale

With ~500 transactions per month it is enough and removes an infrastructure component.

## Consequences

- Revisit and move to Redis when queue latency or load justifies it (plan section 24 defines the metric).
