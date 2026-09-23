# ADR-0023: Invoice (CFDI) issuing: out of scope

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-023

## Context

Mexican merchants issue CFDI invoices for their sales.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

The platform does not issue CFDI or tax files. The tenant can use `metadata` to relate payments to its own invoicing. No mandatory tax fields are added to the checkout; `tax_id` exists in the catalog as an optional field, disabled by default (plan section 19).

## Rationale

Business decision; invoicing is outside the product's scope.

## Consequences

- CFDI issuing is listed in plan section 28 as out of scope.
