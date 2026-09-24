# AxisPay

A multi-tenant **payment-links orchestrator** on Stripe Connect.

- Tenant systems request payment links through an API.
- Payers pay on a hosted checkout on our own domain.
- Each tenant manages users, roles and payments in its own panel.
- Platform staff operate every tenant from a separate superadmin panel.

`axispay` / "AxisPay" is the internal name used in code and infrastructure. The public name shown to people is configurable with `AXISPAY_DISPLAY_NAME` (default "AxisPay"); change that one to rebrand, never `APP_NAME` (ADR-0037).

| Surface | Host | For |
|---|---|---|
| Public API v1 | `api.<domain>` | Tenant systems (API keys) |
| Tenant panel | `app.<domain>` | Tenant users (Filament) |
| Platform panel | `admin.<domain>` | Superadmins (Filament) |
| Checkout | `pay.<domain>` | Payers (Blade + Stripe Payment Element) |

## Status

Phases from the [master plan, section 27](docs/plans/master.md#27-plan-de-implementación-por-fases):

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundations: tooling, MariaDB, `Shared` module, logging, CI | Done |
| 1 | Tenancy, identity, RBAC, 2FA, audit log, admin and app panels | Done |
| 2 | Stripe connection (`platform_onboarding`), gateway port, Connect webhooks | Pending |
| 3 | API keys, idempotency, payment-links API | Pending |
| 4 | Checkout and card payments | Pending |
| 4B | `oauth` and `api_key` connection methods | Pending |
| 5 | Outgoing webhooks and pre-payment validation | Pending |
| 6 | Currency conversion (Banxico) | Pending |
| 7 | Refunds and disputes | Pending |
| 8 | Metrics, branding, payer fields | Pending |
| 9 | Plans, usage reports, operations | Pending |
| 10 | Hardening and go-live | Pending |

Deployment: a production container image and a Dokploy guide exist ([deployment/dokploy.md](docs/deployment/dokploy.md)).

## Quickstart

```sh
docker compose up -d          # MariaDB 11.8 on port 33061
composer install
cp .env.example .env && php artisan key:generate
pnpm install && pnpm run build
php artisan migrate --seed
php artisan db:seed --class=DevelopmentSeeder   # local demo accounts
php artisan serve             # http://app.localhost:8000, http://admin.localhost:8000
```

The full guide, with local accounts, environment keys and quality checks, is in [docs/development.md](docs/development.md).

## Documentation

| Read | When |
|---|---|
| [rules.md](rules.md) | Always first: the non-negotiable project rules (money, tenancy, Stripe, security) |
| [docs/plans/master.md](docs/plans/master.md) | The plan and source of truth (Spanish) |
| [docs/architecture.md](docs/architecture.md) | How the system is built today |
| [docs/README.md](docs/README.md) | Index of every document |
| [docs/adr/](docs/adr/README.md) | Architecture decision records |
| [docs/deployment/dokploy.md](docs/deployment/dokploy.md) | Production deployment |
| [docs/frontend/README.md](docs/frontend/README.md) | Before touching any UI |
| [CHANGELOG.md](CHANGELOG.md) | What changed |

## Tech stack

| Area | Choice |
|---|---|
| Language / framework | PHP 8.4+ (`strict_types`), Laravel 13 |
| Panels | Filament 5 (`admin`, `app`) |
| Database | MariaDB 11.8 LTS, one shared schema with `tenant_id` |
| Queues / cache / sessions | Laravel `database` drivers |
| Money | `brick/money` (minor units, never floats) |
| Permissions | `spatie/laravel-permission` with teams (team = tenant) |
| Frontend | Blade, Tailwind CSS 4, Vite 8, self-hosted Jost; pnpm |
| Errors | Sentry SDK (Sentry or GlitchTip) |
| Quality | Pest, Larastan (level max) with a custom tenancy rule, Pint, GitHub Actions |
| Runtime | Nginx + PHP-FPM container on Dokploy (no Octane) |

## Key rules

The full list is in [rules.md](rules.md). The ones most often at stake:

- Money is always an integer in minor units, never a float.
- Every tenant model uses `BelongsToTenant`, and every new endpoint has an isolation test.
- Business logic lives in Actions, never in controllers or Filament resources.
