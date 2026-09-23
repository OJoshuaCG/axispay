# ADR-0021: Payer fields: fixed catalog, configurable per tenant and per link

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-021

## Context

Tenants need to collect different payer data.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

The platform offers a catalog of fields (email, name, phone, address, etc.). Each tenant defines for each field whether it is `hidden`, `optional` or `required`, and each link can override that configuration through the API.

## Rationale

A known catalog can be validated, encrypted and retained consistently.

## Consequences

- Plan section 19. PII is encrypted at rest and has a retention policy.
