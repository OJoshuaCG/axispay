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
| `ProviderEvents` | Incoming provider webhooks: verification, storage, dispatch (account and payment events; foreign payments ignored). | Phase 2 (exists; payment events since 4) |
| `ApiKeys` | API keys (issuing, hashing, verifying, scoping, revoking) and the rest of the public API's access layer: key authentication, rate limiting per key, failed-authentication limit, idempotency. The name follows plan 4.3. | Phase 3 (exists) |
| `PaymentLinks` | Link creation, expiration, cancellation and queries; link state machine; cancelling links when a gateway disconnects. | Phase 3 (exists) |
| `Checkout` | Public payment page (pay host), the pay flow (authorize, validation hook, capture), 3D Secure continuation, status polling, card-testing protection (rate limits, Turnstile, long block and unblock), openings, security headers, sandbox demo command; the return to the merchant with its signed proof and the per-tenant return secret (ADR-0064). | Phase 4 (exists) |
| `Payments` | Payment attempts, declines and encrypted payer data; attempt state machine; applying the gateway's state (checkout, webhooks, reconciliation); capture, void, reconciliation; the pre-payment validation extension point; the panel's payment history (ADR-0059); refunds, voiding an authorization by API and disputes, their events and their read-only panel section (ADR-0066). | Phase 4 (exists) / 7 |
| `Webhooks` | Recorded business events (`domain_events`, Phase 4). Tenant endpoints, outbox delivery, signed delivery, retries, delivery log, shared SSRF protection, pre-payment validation; their panel screens (Settings → Webhooks, Settings → Pre-payment validation). | Phase 4 (events) / 5 |
| `Fx` | Exchange rates (Banxico FIX job and storage), immutable quotes, conversion policy, the quoter and the conversion of a link's line items (ADR-0063, ADR-0064). | Phase 6 subset (the `FxMode` enum exists since Phase 3); the rest of Phase 6, such as reports by charged currency, is pending |
| `Branding` | The platform logo, favicon and display mode (ADR-0053), the merchant's logo and its tenant-panel "Brand" page (ADR-0056 part B), the shared image checks (`ImageNormalizer`); colors, display name and contrast validation later. The pay-host route that serves the merchant's logo lives in `Checkout`. | ADR-0053, ADR-0056; the rest Phase 8 |
| `Legal` | The merchant's and the platform's privacy notice and terms (text or link), their panel pages, safe Markdown rendering (ADR-0056). The pay-host pages that show them live in `Checkout`. | ADR-0056 (brought forward from Phases 8 and 10) |
| `PayerFields` | Payer field catalog, tenant/link configuration, validation, encrypted storage. | Phase 8 (the catalog enums exist since Phase 3; the checkout's validation and country list since Phase 4) |
| `Reporting` | Daily rollups, metrics, monthly usage reports. | Phase 8 / 9 |
| `Billing` | Versioned pricing plans and fee calculation for usage reports (no charging). | Phase 9 |
| `Notifications` | Operational e-mails. | Phase 9 |

Only `StripeClientFactory` builds a `StripeClient`; nothing outside
`Gateways\Stripe` knows Stripe IDs, statuses or event names (ADR-019).
`FakePaymentGateway` (tests/Support) implements the whole port for tests.
`Gateways\Sandbox` is the checkout sandbox (local and testing only; the
application refuses to boot with it anywhere else, ADR-0051).

Filament resources live inside their module (`<Module>/Filament/...`) and are
registered explicitly in `app/Providers/Filament/{Admin,App}PanelProvider.php`.
Shared panel configuration (theme, palettes, font, render hooks) is in
`app/Support/Filament`.

Code outside `app/Modules` that predates the module structure
(`app/Enums/PaymentStatus.php`, `app/Support/*`, locale middleware and
controller) belongs to the frontend design system and stays where the
frontend docs reference it.
