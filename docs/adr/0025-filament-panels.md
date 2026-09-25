# ADR-0025: Panels: Filament for `admin` and `app`, Blade for checkout

- **Status:** Accepted (by project owner delegation, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** master plan sections 4.1, 4.2 and 5; `docs/frontend/README.md`

## Context

The plan names Filament for the two back-office panels (plan section 5: "two panels: `app` (tenant) and `admin` (platform)") and Blade + light JS for the checkout. The repository already ships a design system (Tailwind CSS v4 tokens in `resources/css/tokens`, Jost, light/dark themes, English/Spanish, Blade components) that all UI must follow. The panels must not look or behave like a separate product.

## Options considered

1. **Filament panels, themed with the existing design tokens.** Fast CRUD and forms, built-in MFA, and a theming layer that can consume our tokens.
2. **Hand-built Blade panels** using only the design-system components. Full control, but much more code for tables, filters, forms and authorization screens.
3. **A SPA (Inertia/Vue/React) for the panels.** Adds a second frontend stack the plan does not call for.

## Decision

- Two Filament panels: `admin` (platform, `platform` guard, on the admin host) and `app` (tenant, on the app host). They are created in Phase 1.
- Both panels are themed from the existing design tokens (colors from `resources/css/tokens/*`, Jost, light/dark, English/Spanish translations). A Filament theme must read the tokens, never redefine values.
  - *Update 2026-09-25 ([ADR-0042](0042-typography-mukta-geist-mono.md)):* the fonts are now Mukta (text) and Geist Mono (currency and numeric data).
- The checkout (`pay` host) stays on Blade + design-system components + Stripe.js, without Filament.
- Filament resources contain no business logic; they call Actions (rules.md rule 12).

## Rationale

Filament is the plan's choice and covers the panel needs (tables, filters, forms, MFA) with the least code, which leaves engineering effort for correctness and security. Theming it with our tokens keeps one visual language. The checkout must be light and fully controlled (CSP, no SPA), which Blade provides.

## Consequences

- The Filament version must be verified against Laravel 13 before Phase 1 installs it (plan section 5).
- Panel UI follows `docs/frontend/README.md`: tokens only, every string through `__()` in `lang/en` and `lang/es`, verified at 320px and in both themes.
- Tenant isolation tests cover every Filament resource (plan 6.6).
