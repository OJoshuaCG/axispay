# ADR-0004: Tenant connection with Stripe: three supported methods

- **Status:** Accepted (risk accepted for the `api_key` method)
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-004

## Context

The initial idea was OAuth. The main audience is merchants **without** a payment gateway (many without a Stripe account), but merchants who already use Stripe should also be served. The project owner decided to support all three methods.

## Options considered

1. **`platform_onboarding`**: Accounts API with controller properties + Account Links (Stripe-hosted onboarding). For merchants without a Stripe account. It is Stripe Connect: platform key + `Stripe-Account` header; single platform Connect webhook endpoint; no merchant secrets stored; disconnection via `account.application.deauthorized`. Default in the UI.
2. **`oauth`**: OAuth Connect for existing Standard accounts. It is Stripe Connect: platform key + `Stripe-Account`; single Connect webhook endpoint; only `stripe_user_id` stored (the `access_token` is not used). Secondary option.
3. **`api_key`**: the merchant registers its **restricted key** (`rk_`) and **publishable key** (`pk_`). Not Stripe Connect: calls use the merchant key without the header; Stripe.js uses the merchant `pk`; a webhook endpoint per connection is created by the platform in the merchant account with its own secret; the restricted key and webhook secret are stored encrypted; disconnection is detected through authentication errors and a periodic health check. Advanced option with warnings.

## Decision

Support the three methods (enum `connection_method`).

## Rationale

Serve both merchants without Stripe (main audience) and merchants with an existing Stripe account, including when OAuth is not available or not wanted.

## Accepted risks and hard rules

- Stripe states that OAuth is not recommended for new Connect platforms and, since 2021, cannot connect accounts controlled by another platform with `read_write`. OAuth availability for our platform must be verified in the Connect dashboard before implementing it (open question 1a); if unavailable, `oauth` is disabled by configuration without affecting the other methods.
- The `api_key` method makes the platform a custodian of merchant credentials: **critical security risk accepted by the project owner**, mitigated by the mandatory controls of plan 12.3.3.
- `api_key` rules: only restricted keys (`rk_test_` / `rk_live_`), secret keys (`sk_`) are always rejected; `pk_` and `rk_` must belong to the same account and mode; `rk_` permissions are validated on connect (neither too few nor excessive on dangerous resources); encryption with a dedicated key (`GATEWAY_CREDENTIALS_KEY`), distinct from `APP_KEY`; the tenant explicitly accepts a risk notice in the panel (recorded in the audit log).

## Consequences

- `gateway_connections` includes `connection_method` and encrypted credential columns (plan 7.4).
- `StripeGateway` uses a `StripeClientFactory` that builds the call context per method (plan 12.4.1). The domain does not know the connection method.
- Three connection flows in the panel, each with its own tests (phase 2 split into 2A, 2B and 2C).
- A tenant has at most **one active connection per provider and mode**, regardless of the method. Changing method requires disconnecting the previous one.
