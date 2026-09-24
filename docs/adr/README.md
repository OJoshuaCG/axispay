# Architecture decision records

One decision per file, format: Context, Options considered, Decision, Rationale, Consequences. ADR-0001 to ADR-0024 mirror section 3 of the master plan (`docs/plans/master.md`); the plan stays the source of truth. A change to an accepted decision is a new ADR that supersedes the old one and states why.

| ADR | Title | Status |
|---|---|---|
| [ADR-0001](0001-modular-monolith.md) | Architectural style: modular monolith | Accepted |
| [ADR-0002](0002-shared-database-tenancy.md) | Tenancy: one shared database with tenant_id | Accepted |
| [ADR-0003](0003-mariadb-lts.md) | Database engine: self-managed MariaDB LTS | Accepted |
| [ADR-0004](0004-stripe-connection-methods.md) | Tenant connection with Stripe: three supported methods | Accepted (risk accepted for the `api_key` method) |
| [ADR-0005](0005-own-domain-payment-element.md) | Links live on our domain; charges use PaymentIntent + Payment Element | Accepted |
| [ADR-0006](0006-single-use-reopenable-links.md) | Link semantics: single use, reopenable | Accepted |
| [ADR-0007](0007-money-minor-units.md) | Money: integers in minor units; the API takes decimal strings | Accepted |
| [ADR-0008](0008-outgoing-webhooks-standard-webhooks.md) | Outgoing webhooks: HMAC-SHA256 following Standard Webhooks | Accepted |
| [ADR-0009](0009-fx-card-detection-and-confirmation.md) | USD to MXN conversion: card detection + explicit confirmation | Accepted |
| [ADR-0010](0010-fx-rate-source.md) | Exchange-rate source: Banxico FIX (SIE API) or fixed rate per link | Accepted |
| [ADR-0011](0011-external-platform-billing.md) | Platform billing to its customers: outside the payment flow | Accepted |
| [ADR-0012](0012-fees-on-refunds-and-disputes.md) | Platform fees on refunds and disputes | Accepted |
| [ADR-0013](0013-suspended-tenants-keep-collecting.md) | Tenant suspension: existing links keep collecting | Accepted |
| [ADR-0014](0014-rbac-permissions.md) | RBAC with platform permissions; roles as permission sets | Accepted |
| [ADR-0015](0015-api-keys-own-table.md) | API keys in their own table, owned by the tenant (not a user) | Accepted |
| [ADR-0016](0016-database-queue.md) | Queues and async work: Laravel database driver | Accepted |
| [ADR-0017](0017-own-db-source-of-truth.md) | Payments source of truth: own database fed by webhooks + reconciliation | Accepted |
| [ADR-0018](0018-card-only-mvp.md) | MVP payment methods: card only | Accepted |
| [ADR-0019](0019-gateway-port-adapter.md) | Gateway abstraction (port/adapter) from day one, without over-engineering | Accepted |
| [ADR-0020](0020-ulid-prefixed-ids.md) | Identifiers: ULID primary keys, prefixed in the API | Accepted |
| [ADR-0021](0021-payer-fields-catalog.md) | Payer fields: fixed catalog, configurable per tenant and per link | Accepted |
| [ADR-0022](0022-email-only-notifications.md) | Notifications: e-mail only, no interaction with payers | Accepted |
| [ADR-0023](0023-no-cfdi.md) | Invoice (CFDI) issuing: out of scope | Accepted |
| [ADR-0024](0024-pre-payment-validation.md) | Pre-payment validation: synchronous callback, separate from webhooks | Accepted |
| [ADR-0025](0025-filament-panels.md) | Panels: Filament for `admin` and `app`, Blade for checkout | Accepted (by project owner delegation, 2026-09-23) |
| [ADR-0026](0026-local-and-production-database.md) | Local database in Docker; production on an external MariaDB server | Accepted (by project owner delegation, 2026-09-23) |
| [ADR-0027](0027-local-surface-hosts.md) | Surface hosts for local development | Accepted (by project owner delegation, 2026-09-23) |
| [ADR-0028](0028-product-naming.md) | Product naming in code | Superseded by ADR-0037 |
| [ADR-0029](0029-error-tracking-integration.md) | Error tracking integration (Sentry SDK, compatible with GlitchTip) | Proposed. The provider choice is open question #13 (Sentry vs self-hosted GlitchTip). |
| [ADR-0030](0030-filament-theme-and-dark-mode-bridge.md) | Filament 5 panels themed from the design tokens, with a dark-mode bridge | Accepted (Phase 1) |
| [ADR-0031](0031-tenancy-enforcement.md) | Tenancy enforcement details (whitelist, platform rows, permission teams) | Accepted (Phase 1) |
| [ADR-0032](0032-identity-invitations-reauthentication-2fa.md) | Identity: 2FA, invitations and re-authentication | Accepted (Phase 1) |
| [ADR-0033](0033-impersonation.md) | Audited impersonation across hosts | Accepted (Phase 1) |
| [ADR-0034](0034-phase-1-security-hardening.md) | Phase 1 security hardening (escalation guard, locks, session cookies, throttling, accepted enumeration risk) | Accepted (Phase 1) |
| [ADR-0035](0035-production-container-image.md) | Production container image (Nginx + PHP-FPM, one image, several roles) | Accepted (by project owner delegation, 2026-09-24) |
| [ADR-0036](0036-dokploy-deployment.md) | Deployment on Dokploy with one Application per role | Accepted (Dokploy chosen by the project owner, 2026-09-24) |
| [ADR-0037](0037-product-naming-axispay.md) | Product naming: AxisPay internal name, configurable display name, `axp_` key prefix | Accepted (by the project owner, 2026-09-24) |
| [ADR-0038](0038-platform-branding-powered-by.md) | Platform branding: platform logo and an always-visible "Powered by" on the checkout | Accepted (by the project owner, 2026-09-24) |
| [ADR-0039](0039-all-in-one-container-role.md) | All-in-one container role for staging and local deployments | Accepted (by the project owner, 2026-09-24) |
