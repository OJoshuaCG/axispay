# ADR-0019: Gateway abstraction (port/adapter) from day one, without over-engineering

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-019

## Context

Other gateways (Mercado Pago, Conekta) are a future differentiator, but only Stripe is implemented now.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

A `PaymentGateway` interface used by the domain and a `StripeGateway` implementation. The interface is designed with **two** gateways in mind (Stripe with iframe and on-page confirmation; Mercado Pago with redirection), although only Stripe is implemented. The adapter is chosen by a simple factory per `provider`, without plugins or dynamic loading.

## Rationale

Keeps the unified, gateway-independent API possible without paying for a plugin architecture.

## Consequences

- Plan section 12.1 defines the port. No code outside the adapter knows Stripe IDs or states.
