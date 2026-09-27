# ADR-0031: Tenancy enforcement details (Phase 1)

- **Status:** Accepted (Phase 1, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** master plan sections 6.1–6.6, ADR-0002, ADR-0014

## Context

Plan section 6 fixes the layers (scoped `TenantContext`, fail-closed `BelongsToTenant`, composite foreign keys, a PHPStan rule, isolation tests). Implementing them raised details the plan leaves open.

## Decision

1. **Single list of tenant tables:** `config/tenancy.php` (`tenant_tables`, `team_scoped_tables`, `scope_bypass_whitelist`). The PHPStan rule reads it with `require` (no framework boot); a test fails when a table with `tenant_id` is missing from it or a model on such a table lacks `BelongsToTenant`.
2. **Platform rows in `audit_logs`:** `tenant_id` is nullable. A model implementing `AllowsPlatformRows` keeps an **explicit** NULL; an absent `tenant_id` is still filled from the context. Platform rows never match the tenant scope.
3. **Writes into another tenant** from inside a tenant context throw `TenantMismatchException`; use `TenantContext::runAsTenant()`.
4. **Platform context audit:** `runAsPlatform()` dispatches `PlatformContextEntered`, recorded by the Audit module (keeps Tenancy independent of Audit). The reason is mandatory.
5. **Scope-bypass whitelist additions for Phase 1** (authentication entry points that run before the tenant is known, like `ApiKeyAuthenticator` for the API):
   - `Identity\Auth\TenantUserProvider` — the `web` guard's user provider (the tenant is derived from the user).
   - `Identity\Services\InvitationLookup` — finds an invitation by token hash for a signed-out invitee.
   - `Identity\Services\UserDirectory` — platform-wide e-mail uniqueness (returns a boolean only).
   - The `PlatformAdmin\` module was already whitelisted by the plan (admin audit log, impersonation lookups).
   - Phase 2 ([ADR-0047](0047-stripe-connection-phase-2-and-api-key-reordering.md)): `Gateways\Services\GatewayConnectionResolver` (already on the plan's list) now exists and holds every cross-tenant lookup of gateway connections: webhook routing, uniqueness across tenants, health-check scheduling, key rotation and the platform panel's read-only view. `ProviderEvents\Services\ProviderEventRetention` deletes old ignored/unroutable rows and reduces old payloads across tenants (plan 14.4); every other write to `provider_events` happens in its tenant context, or as an explicit platform row (`AllowsPlatformRows`) when unroutable.
   - Phase 3 ([ADR-0048](0048-payment-links-api-phase-3.md)): `ApiKeys\Services\ApiKeyAuthenticator` and `PaymentLinks\Services\PaymentLinkLookup` (both on the plan's list) now exist: the first finds a key by its hash before the tenant is known, the second returns only the identifiers of links due for expiration. `ApiKeys\Services\IdempotencyRecordRetention` deletes expired idempotency records of every tenant and never reads their content.
   - Webhook activity ([ADR-0050](0050-linear-payment-flow-authorize-validate-capture.md)): `ProviderEvents\Services\ProviderEventActivity` tells when gateway events last arrived, per mode and per connection, for `axispay:doctor` and the platform panel. It reads timestamps and counts only, never payloads, and writes nothing. It is whitelisted instead of using `runAsPlatform()` because the doctor must stay a pure read, and entering the platform context writes an audit row.
   - Phase 4 ([ADR-0051](0051-checkout-and-card-payments-phase-4.md)): `PaymentLinkLookup` gains the checkout's public-token lookup (identifiers only; the tenant context is set from the row before anything else is read). `Payments\Services\PaymentAttemptLookup` tells the reconciliation scheduler which tenants and modes have open payment attempts or links in `processing` (identifiers only, no writes); each reconciliation then runs inside its tenant's context. `ProviderEvents\Services\ProviderEventInbox` finds stored gateway events across tenants for their recovery (a duplicate delivery, events stuck in `received`, unroutable events waiting for their connection, failed events to retry); every change is then made in the event's own tenant context, except removing an unroutable platform row when its routed copy replaces it. `GatewayConnectionResolver` gains the connection of the attempt a payment event names (identifiers only).
6. **Test code may call `withoutGlobalScope(s)`** to assert cross-tenant state; raw SQL on tenant tables is still reported there. Migrations are exempt (DDL, triggers).
7. **Raw SQL that is not a literal** is reported, because the rule cannot inspect it.
8. **spatie/laravel-permission:** `team_id` holds the tenant ULID and follows the TenantContext (`Access\TenantTeamResolver`); setting a team explicitly is refused, because the `PermissionRegistrar` is a singleton and a stored team would leak into the next queued job. System roles are global (`roles.team_id` NULL). `model_has_roles` / `model_has_permissions` have a composite FK `(team_id, model_id) → users (tenant_id, id)`. `Role` has no global scope (spatie caches the role map across tenants); tenant-facing queries use `Role::visibleToCurrentTenant()`.
9. **`audit_logs` is append-only in the database too:** `BEFORE UPDATE` / `BEFORE DELETE` triggers raise an error.
10. **Tenant panel resolution:** `ResolveTenantContext` (Filament auth middleware, persistent for Livewire requests) sets the tenant from the user and the mode from the session selector (test by default). The admin panel never sets a tenant context.

## Consequences

- Adding a tenant table means adding it to `config/tenancy.php` (the inventory test enforces it).
- Adding a whitelist entry needs a line in this ADR.
- The rule class lives in `tests/PHPStan/Rules` (dev-only autoload) and is tested with PHPStan's `RuleTestCase`, run by Pest.
