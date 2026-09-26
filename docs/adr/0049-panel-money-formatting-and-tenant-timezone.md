# ADR-0049: Money as number + ISO code, regional number formatting, tenant time zone in the panel

- **Status:** Accepted (orchestrator decisions of 2026-09-26, from the UI/UX review of the Phase 3 screens)
- **Date:** 2026-09-26
- **Source:** UI/UX audit of the payment link and API key screens (Phase 3); master plan sections 7.1 (`tenants.timezone`), 8.3, 6.8; [ADR-0042](0042-typography-mukta-geist-mono.md), [ADR-0044](0044-panel-ux-theme-language-type-surfaces.md), [ADR-0048](0048-payment-links-api-phase-3.md)

## Context

The review of the Phase 3 panel screens found:

1. **Ambiguous money.** Amounts followed each language's own format: in Spanish `1.500,00 MX$` and `1.200,00 US$`, in English `MX$1,500.00` and `$1,200.00`. A bare `$` is both pesos and dollars for the Mexican market, and the Spanish grouping (`1.500,00`) contradicts the amount input, which only accepts `.` as decimal mark (plan 8.2).
2. **Dates in UTC.** The tenant panel showed every date in UTC, while `tenants.timezone` exists (plan 7.1, default `America/Mexico_City`): a link "expiring at 18:00" really expired at noon local time.

## Decision

### 1. Regional number formatting

- The interface language stays per viewer (English or Spanish). Numbers use the **market region** of the language: Spanish → Mexico (`es_MX`), English → United States (`en_US`). In both, `,` groups thousands and `.` separates decimals, the same rule as amount inputs.
- The mapping lives in configuration (`app.formatting_locales`); adding a language adds its region there.

### 2. Money is the number and the ISO code

| Amount | Shown as (both languages) |
|---|---|
| MXN 12,500 | `12,500.00 MXN` |
| USD 1,200 | `1,200.00 USD` |
| USD 0.50 | `0.50 USD` |
| CLP 129,900 | `129,900 CLP` |

- Never a bare `$` or a regional symbol (`MX$`, `US$`). The code always follows the number, separated by a non-breaking space so the pair never splits across lines.
- The number always has the currency's decimals (ISO 4217) and is exact: it never goes through a floating-point value.
- One formatter serves the Blade amount component and the panels (tables, detail heading, dialogs), so every screen shows the same text. Signed amounts keep their `+` / `−` and colors.

### 3. Tenant time zone in the tenant panel

- The tenant panel shows every date and time in the tenant's time zone. Storage stays in UTC (plan 6.8).
- Dates with time read `26 sep. 2026, 14:30` (month in the viewer's language); relative times ("in 3 days") show the exact date in a tooltip.
- The platform panel keeps UTC: its people look at every tenant at once, and one clock avoids mixing zones. Changing that is a separate decision.

### 4. Related panel rules from the same review

- Forms in dialogs do not use the browser's own validation bubbles (always in the browser's language); the server validates and answers in the viewer's language.
- A payment link that can no longer be paid (expired, canceled) does not show its share URL or the copy and cancel actions; a notice at the top says since when it no longer accepts payments (and, if canceled, why).
- The links list has no row actions: a link is canceled from its detail, after a dialog that states the amount, the description and that it cannot be undone.
- Accessibility rules from the visual review: placeholder text meets text contrast; content scrolled into view is never hidden under the sticky top bar; badge icons have the badge's text color; formatted amounts carry the language tag of their number format (`es-MX`, `en-US`) so screen readers read them correctly; every copy control is a real button reachable from the keyboard; a list of permissions is shown in full (or as "All permissions"), never behind a mouse-only "show more"; filters open in a dialog on every screen width.

## Consequences

- Spanish-speaking people see `1,500.00` (Mexican convention) instead of `1.500,00`. A future market with the other convention gets its own formatting locale.
- Amounts are slightly wider (three-letter code instead of a symbol); tables and cards keep amounts on one line.
- Tenants that set a different time zone see their own; the platform panel and exports in later phases must state their zone explicitly.
