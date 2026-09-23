# ADR-0010: Exchange-rate source: Banxico FIX (SIE API) or fixed rate per link

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-010

## Context

Conversions need a trustworthy exchange rate.

## Options considered

1. Fixed rate defined by the user.
2. Automatic Banxico rate.
3. Commercial FX providers.

## Decision

Both configurable modes: `banxico_fix` (FIX series from Banxico's SIE API, fetched by a scheduled job and stored locally, never queried online during a payment) and `fixed` (rate sent by the integrator when creating the link). Tenant-configurable **markup** over FIX, **capped** (platform default: maximum 10% = 1000 bps). The **tenant** bears the exchange-rate risk.

## Rationale

An official, free reference rate for the automatic mode, plus a fixed mode for integrators that manage their own rate.

## Consequences

- Plan section 13 defines fetching, sanity checks, staleness limits and quotes.
