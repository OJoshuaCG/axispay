# ADR-0028: Product naming in code

- **Status:** Superseded by [ADR-0037](0037-product-naming-axispay.md) (2026-09-24). The text below is kept as history; the names it mentions (`paylink`, `plk_`, `config/paylink.php`, "Cirox Payments") are no longer used.
- **Date:** 2026-09-23
- **Source:** master plan header and section 29, open question #10

## Context

The plan uses the working name "PayLink", the code namespace/prefix `paylink` and the API key prefix `plk_`, and states that changing the commercial name must not require domain changes. The application's display name is "Cirox Payments". Open question #10 (final commercial name, domains and key prefix) is still pending and affects Phase 0 only through prefixes.

## Options considered

1. **Keep the working name in code and prefixes; take the display name from configuration.**
2. **Rename everything to the display name now.** Would bake an unconfirmed name into key prefixes and database users.

## Decision

- Code, configuration keys, database names/users, headers and prefixes use the working name: `paylink`, `plk_test_` / `plk_live_`, `config/paylink.php`, `PayLink-Webhooks/1.0` user agent (future).
- Everything shown to people uses `APP_NAME` (currently "Cirox Payments").
- Open question #10 stays pending. If the key prefix changes before any key is issued (Phase 3), it is a one-line change in `config/paylink.php` → `api_key_prefix` plus the redaction patterns.

## Rationale

Separates a still-open business decision from code that must stay stable once integrators depend on it.

## Consequences

- API keys are issued from Phase 3; the prefix should be confirmed before then, because changing it afterwards breaks integrators' secret scanning and log redaction rules.
