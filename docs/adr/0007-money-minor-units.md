# ADR-0007: Money: integers in minor units; the API takes decimal strings

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-007

## Context

The initial idea was to store the amount as a float/decimal on our side.

## Options considered

1. **`FLOAT`/`DOUBLE`.** Forbidden: inexact binary representation (`0.1 + 0.2 != 0.3`).
2. **`DECIMAL(p,2)`.** Exact, but assumes 2 decimals; currencies such as JPY (0) or KWD (3) would force a migration.
3. **`BIGINT` in minor units + `CHAR(3)` currency**, with a table or config of exponents per currency.

## Decision

Option 3 in the database and the domain. **The API accepts and returns amounts as decimal strings** (`"150.50"`), never as JSON numbers (many parsers turn them into doubles). The string/minor conversion happens at the boundary, without floats, using `brick/money`.

## Rationale

Exactness for every currency exponent, and protection against clients whose JSON parsers use doubles.

## Consequences

- Plan section 8 defines parsing, validation and rounding rules (implemented in Phase 0 by the `Shared\Money` module).
