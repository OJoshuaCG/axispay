# Documentation index

Start with [`../rules.md`](../rules.md) (non-negotiable rules), then the plan.

## Plan and decisions

| Document | What it is |
|---|---|
| [plans/master.md](plans/master.md) | Master plan: scope, data model, API contract, security, phases. The source of truth (Spanish). |
| [adr/README.md](adr/README.md) | Index of the architecture decision records (ADR-0001 to ADR-0036) |

## Building

| Document | What it is |
|---|---|
| [architecture.md](architecture.md) | Surfaces and hosts, request flow, modules, tenancy layers, identity and RBAC, audit, panels, i18n, data conventions |
| [development.md](development.md) | Local setup with Docker MariaDB, environment keys, local accounts, quality checks, CI |
| [../app/Modules/README.md](../app/Modules/README.md) | Module map and each module's public entry points |
| [api/openapi.yaml](api/openapi.yaml) | Public API v1 contract (OpenAPI 3.1; endpoints arrive in Phase 3) |

## Frontend

| Document | What it is |
|---|---|
| [frontend/README.md](frontend/README.md) | Design-system rules and "Where do I change X?". Read it before touching UI. |
| [frontend/tokens.md](frontend/tokens.md) | Design tokens (primitives and semantic) |
| [frontend/theming.md](frontend/theming.md) | Light/dark themes and the Filament bridge |
| [frontend/components.md](frontend/components.md) | Blade components |
| [frontend/payments-ui.md](frontend/payments-ui.md) | Payment status and money display |
| [frontend/accessibility.md](frontend/accessibility.md) | Accessibility requirements |
| [frontend/responsive.md](frontend/responsive.md) | Breakpoints and responsive rules |
| [frontend/i18n.md](frontend/i18n.md) | Locales and translation rules |

## Operating

| Document | What it is |
|---|---|
| [deployment/dokploy.md](deployment/dokploy.md) | Step-by-step production deployment on Dokploy: environments, variables, domains, health checks, rollbacks, troubleshooting |
| [adr/0035-production-container-image.md](adr/0035-production-container-image.md) | What the production image does and why |
| [adr/0036-dokploy-deployment.md](adr/0036-dokploy-deployment.md) | Why one Dokploy Application per role, and gaps against plan section 25 |

Runbooks (plan 24.5) will live in `docs/runbooks/` once the operations they cover exist.

## History

| Document | What it is |
|---|---|
| [../CHANGELOG.md](../CHANGELOG.md) | Notable changes (Keep a Changelog) |
