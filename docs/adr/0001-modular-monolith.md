# ADR-0001: Architectural style: modular monolith

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-001

## Context

Small team, low volume, and the need to reach production quickly with high correctness.

## Options considered

1. **Microservices.** Pros: independent deployment. Cons: disproportionate operational complexity, distributed transactions, hard observability.
2. **Modular monolith** with clear boundaries between modules. Pros: simple, transactional, easy to test. Cons: requires discipline to avoid coupling modules.
3. **Unstructured monolith.** Cons: fast technical debt.

## Decision

Modular monolith (option 2).

## Rationale

It keeps the system simple and transactional, which matches the main risks of the product (correctness, security and operations rather than scale; plan section 2.4).

## Consequences

- One repository and one deployment.
- Modules communicate through interfaces and internal domain events, not by reaching into other modules' models arbitrarily.
- Extracting services later stays possible but is not planned.
