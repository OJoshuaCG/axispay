# Domain modules

The application is a modular monolith (ADR-001). Each module lives in
`app/Modules/<Module>/` and, as it grows, uses these subfolders (plan section
4.3): `Models`, `Actions`, `Data` (DTOs), `Enums`, `Events`, `Jobs`, `Http`
(controllers, requests, resources), `Policies`, `Services`, `Exceptions`,
`Providers`.

Rules:

- A module exposes a small set of public Actions or Services. Other modules use
  those instead of touching its models directly.
- Business logic lives in single-purpose Actions with immutable DTOs, never in
  controllers or Filament resources (rules.md rule 12).
- Create a module folder only when the phase that needs it starts. Empty
  placeholder folders are not added.

## Module map

| Module | Responsibility | Introduced in |
|---|---|---|
| `Shared` | Money (brick/money), prefixed IDs and ULID keys, API error format, request IDs, log and error-tracker redaction. **Not a catch-all**: only cross-cutting primitives with no business rules. | Phase 0 (exists) |
| `Tenancy` | Tenants, `TenantContext`, `BelongsToTenant` / `BelongsToMode`, tenant resolution per surface, tenant states and what each state allows (panel read-only, API access). | Phase 1 (exists) |
| `Identity` | Tenant users, authentication, 2FA, invitations, re-authentication for sensitive actions. | Phase 1 (exists) |
| `Access` | Permissions and roles (spatie/laravel-permission with teams), policies. | Phase 1 (exists) |
| `PlatformAdmin` | Superadmins, audited impersonation, global views. | Phase 1 (exists) |
| `Audit` | Append-only audit log. | Phase 1 (exists) |
| `Gateways` | `PaymentGateway` port, `StripeGateway`, `StripeClientFactory`, gateway connections and connection flows, credential encryption, health checks, whether a tenant can charge in a mode. | Phase 2 (exists; OAuth in 4B) |
| `ProviderEvents` | Incoming provider webhooks: verification, storage, dispatch, reconciliation. | Phase 2 (exists; reconciliation in 4) |
| `ApiKeys` | API keys (issuing, hashing, verifying, scoping, revoking) and the rest of the public API's access layer: key authentication, rate limiting per key, failed-authentication limit, idempotency. The name follows plan 4.3. | Phase 3 (exists) |
| `PaymentLinks` | Link creation, expiration, cancellation and queries; link state machine; cancelling links when a gateway disconnects. | Phase 3 (exists) |
| `Checkout` | Public payment page, quotes, attempt start/confirmation, card-testing protection. | Phase 4 |
| `Payments` | Payment attempts, refunds, disputes; attempt state machine. | Phase 4 / 7 |
| `Webhooks` | Tenant endpoints, outbox, signed delivery, retries, delivery log, shared SSRF protection, pre-payment validation. | Phase 5 |
| `Fx` | Exchange rates (Banxico), quotes, conversion policy. | Phase 6 (the `FxMode` enum exists since Phase 3: links and tenant settings store it) |
| `Branding` | Logo, colors, display name; contrast validation. | Phase 8 |
| `PayerFields` | Payer field catalog, tenant/link configuration, validation, encrypted storage. | Phase 8 (the catalog enums `PayerField` / `PayerFieldRequirement` exist since Phase 3) |
| `Reporting` | Daily rollups, metrics, monthly usage reports. | Phase 8 / 9 |
| `Billing` | Versioned pricing plans and fee calculation for usage reports (no charging). | Phase 9 |
| `Notifications` | Operational e-mails. | Phase 9 |

Only `StripeClientFactory` builds a `StripeClient`; nothing outside
`Gateways\Stripe` knows Stripe IDs, statuses or event names (ADR-019).
`FakePaymentGateway` (tests/Support) implements the whole port for tests.

Filament resources live inside their module (`<Module>/Filament/...`) and are
registered explicitly in `app/Providers/Filament/{Admin,App}PanelProvider.php`.
Shared panel configuration (theme, palettes, font, render hooks) is in
`app/Support/Filament`.

Code outside `app/Modules` that predates the module structure
(`app/Enums/PaymentStatus.php`, `app/Support/*`, locale middleware and
controller) belongs to the frontend design system and stays where the
frontend docs reference it.
