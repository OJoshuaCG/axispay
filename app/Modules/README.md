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
| `Tenancy` | Tenants, `TenantContext`, `BelongsToTenant` / `BelongsToMode`, tenant resolution per surface, tenant states. | Phase 1 |
| `Identity` | Tenant users, authentication, 2FA, invitations, re-authentication for sensitive actions. | Phase 1 |
| `Access` | Permissions and roles (spatie/laravel-permission with teams), policies. | Phase 1 |
| `PlatformAdmin` | Superadmins, audited impersonation, global views. | Phase 1 |
| `Audit` | Append-only audit log. | Phase 1 |
| `Gateways` | `PaymentGateway` port, `StripeGateway`, `StripeClientFactory`, gateway connections and connection flows, credential encryption, health checks. | Phase 2 / 4B |
| `ProviderEvents` | Incoming provider webhooks: verification, storage, dispatch, reconciliation. | Phase 2 |
| `ApiKeys` | Issuing, hashing, verifying, scoping and revoking API keys. | Phase 3 |
| `PaymentLinks` | Link creation, expiration, cancellation and queries; link state machine. | Phase 3 |
| `Checkout` | Public payment page, quotes, attempt start/confirmation, card-testing protection. | Phase 4 |
| `Payments` | Payment attempts, refunds, disputes; attempt state machine. | Phase 4 / 7 |
| `Webhooks` | Tenant endpoints, outbox, signed delivery, retries, delivery log, shared SSRF protection, pre-payment validation. | Phase 5 |
| `Fx` | Exchange rates (Banxico), quotes, conversion policy. | Phase 6 |
| `Branding` | Logo, colors, display name; contrast validation. | Phase 8 |
| `PayerFields` | Payer field catalog, tenant/link configuration, validation, encrypted storage. | Phase 8 |
| `Reporting` | Daily rollups, metrics, monthly usage reports. | Phase 8 / 9 |
| `Billing` | Versioned pricing plans and fee calculation for usage reports (no charging). | Phase 9 |
| `Notifications` | Operational e-mails. | Phase 9 |

## Shared module contents

| Area | Entry points |
|---|---|
| Money | `Money\Money`, `Money\AmountParser`, `Money\ExchangeRate`, `Money\CurrencyCode`, `Money\CurrencyLimits`, `Money\Rounding` |
| IDs | `Ids\PrefixedId`, `Ids\ResourceType`, `Ids\Ulid`, `Database\HasUlidPrimaryKey`, `Database\HasPrefixedId` |
| Schema | `Database\SchemaMacros` (`ulidAscii`, `foreignUlidAscii`, `asciiString`, `asciiChar`, `->asciiBin()`) |
| API errors | `Http\Errors\ApiErrorCode`, `ApiErrorType`, `ApiException`, `ApiErrorRenderer`, `ApiSurface` |
| Request IDs | `Http\RequestId`, `Http\Middleware\AssignRequestId` |
| Redaction | `Logging\Redactor`, `Logging\RedactSensitiveDataProcessor`, `Logging\RedactSensitiveLogData`, `Observability\SentryEventScrubber` |

Code outside `app/Modules` that predates the module structure
(`app/Enums/PaymentStatus.php`, `app/Support/*`, locale middleware and
controller) belongs to the frontend design system and stays where the
frontend docs reference it.
