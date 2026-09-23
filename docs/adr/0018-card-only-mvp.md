# ADR-0018: MVP payment methods: card only

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-018

## Context

Several payment methods are popular in Mexico (OXXO, SPEI, installments, wallets).

## Options considered

The master plan records this decision without listing alternative options.

## Decision

`payment_method_types = ['card']`. OXXO, SPEI, installments (meses sin intereses), Apple Pay and Google Pay are left for later phases.

## Rationale

Asynchronous methods (OXXO, SPEI) break the immediate-confirmation assumption and need additional states; wallets conflict with the FX flow (ADR-009).

## Consequences

- The state model already includes `processing`, so it does not need a redesign later.
