# ADR-0044: Panel UX: default light theme, visible theme and language controls, panel type scale for Mukta, surface layering

- **Status:** Proposed
- **Date:** 2026-09-25
- **Source:** UI/UX audit of the admin and app panels (screenshots and measurements with the production image, 2026-09-25); `docs/frontend/theming.md`, `tokens.md`, `responsive.md`; [ADR-0025](0025-filament-panels.md), [ADR-0030](0030-filament-theme-and-dark-mode-bridge.md), [ADR-0042](0042-typography-mukta-geist-mono.md)

## Context

The audit found five problems in the Filament panels:

1. **Theme.** The panels followed the OS (`system`). The only theme control was Filament's switcher inside the user menu, absent on the sign-in pages, so a person on a dark OS could not choose light before signing in, and most people never found it afterwards.
2. **Type size.** Filament's sizes were tuned for Inter. Mukta's Latin x-height is about 0.47em against about 0.54em for Inter, so the panels' 14px `text-sm` (table cells, sidebar, labels, buttons) read like 12px Inter on desktop. Badges were 12px.
3. **Language switcher.** From 640px up it showed native names ("English" / "Español") in 44px pills: 147px wide in the topbar and on the sign-in page, where it was centred instead of right-aligned (the row lacked `w-full`).
4. **Flat surfaces.** Cards, table and page were all white on near-white in light, and dark cards (`gray-900`) were almost the page value. The sidebar had no edge or background at `lg`.
5. **Keyboard focus.** Filament hides the outline on sidebar items and tabs and only tints the background, too faint to follow (WCAG 2.4.7).

Also noted: title-cased labels ("Administradores De La Plataforma", "Crear Cliente"), which Spanish style does not use; a redundant "View" row action next to "Edit"; the Spanish Filament strings "Escritorio" and "Entre a su cuenta".

## Options considered

- **Theme default:** keep `system`; or default to `light` with a visible control. Chosen: light, because the product's reference designs and the payment surfaces are light-first, and a visible control makes the choice cheap to change. Existing saved choices are kept: Filament reads `localStorage['theme']` before the default.
- **Type size:** raise the root font size (every rem grows, spacing and layout included); set sizes on individual `.fi-*` classes (dozens of selectors that break on Filament updates); or remap Tailwind's `--text-xs/sm/base` tokens on the panel root. Chosen: the token remap.
- **Language control:** icon + name dropdown; flags; or codes in the same segmented control as the theme. Chosen: codes, with the native name as sr-only text and tooltip (flags name countries, not languages).

## Decision

1. **Default theme light** for both panels (`PanelDefaults`: `->defaultThemeMode(ThemeMode::Light)`) and for the Blade pages (`resources/js/theme.js` `DEFAULT_THEME`, and the pre-paint script in `components/layouts/app.blade.php`). "System" stays available and follows the OS when chosen.
2. **Visible theme control** in the panels: `resources/views/filament/partials/theme-control.blade.php`, a light/dark/system segmented group of `aria-pressed` buttons. It dispatches Filament's own `theme-changed` event, so Filament stays the only writer of `localStorage['theme']` and of the `.dark` class (the ADR-0030 bridge then sets `data-theme`). Shown on the sign-in (simple) pages, next to the language switcher, right-aligned, and in the topbar from `md` up. Filament's user-menu switcher keeps its own state and goes stale when the topbar control is used, so the panel theme hides it from `md` up; below `md` it is the theme control.
3. **Segmented control family**: `seg-group` / `seg-option` utilities (`resources/css/components.css`) shared by the language switcher, the Blade theme toggle and the panel theme control. Options are 44x44px; on the new `desktop:` variant (`width >= 64rem` and `pointer: fine`) they shrink to 36x30 so the group is 36px tall. Touch screens keep 44px at every width. The language switcher always shows the code ("EN", "ES"): the accessible name is "EN English" (label-in-name, WCAG 2.5.3). The app panel's test/live switch is 36px tall on `desktop:` to match.
4. **Panel type scale**: `--panel-text-*` tokens in `theme.css`, remapped on `html.fi` in the panel theme: `text-xs` 13px; `text-sm` 15px below 1024px and 16px from 1024px; `text-base` 17px from 1024px. The Blade pages keep their scale. The remap is unlayered on purpose: the token files are imported before Tailwind declares its layers, so the `@theme` variables are unlayered and a layered override would lose.
5. **Surface layering**: semantic tokens `canvas` (neutral-50 / neutral-900), `raised` (neutral-0 / neutral-700) and `sunken` (neutral-50 / neutral-800). The panel body and sign-in layout use `canvas`; topbar, sidebar, tables, sections, tabs, modals, dropdowns and empty states use `raised` with a `line` ring; table header rows use `sunken` with `fg-secondary` text. The sidebar gets an end border at `lg`, a `primary-subtle` active item with a 3px `link` start bar and `fg` semibold label, `fg-muted` icons and small uppercase group labels. The sign-in page gets a soft `primary-subtle` / `feature-subtle` wash behind a `shadow-md` card.
6. **Visible focus** on sidebar items and group triggers, tabs, topbar items and the notifications button: 2px `focus-ring` outline, inset (-2px) so neither the sidebar's scroll container nor the sticky topbar clips or covers it (2.4.11).
7. **Brand mark** until the ADR-0038 logo exists: `brand-mark` / `brand-mark-fg` tokens and a 28px tile + name partial (`filament/partials/brand.blade.php`) set as `->brandLogo()`, shown in the topbar, the mobile sidebar header and the sign-in card. `->brandName()` stays for titles and alt text.
8. **Copy and tables**: a `SentenceCaseLabels` trait on every resource (singular label as translated, plural with only a capital first letter); page titles name the record (`$recordTitleAttribute`, with global search explicitly off, since a title attribute would switch it on); a shorter "Admins" / "Administradores" navigation label; rows open the view page (Filament's default record URL) and the "View" row action is gone; empty states with icon, heading, description and, for tenants, a create button; tenant profile grouped in a two-column section with a monospace, copyable ID; one primary action per page header ("Change status" is gray); Spanish Filament overrides in `lang/vendor/filament-panels/es` ("Inicio", "Inicie sesión", "Iniciar sesión").

## Rationale

Everything is expressed as tokens or through Filament's own extension points (render hooks, `brandLogo`, `defaultThemeMode`, the `theme-changed` event, lang overrides), so no Filament view is published and updates stay cheap. The type remap changes only font sizes and line heights; rem-based spacing, touch targets and layout are unchanged. One theme source of truth (Filament's storage key and event) avoids two controls fighting.

## Consequences

- People with a dark OS and no saved choice now see light panels and Blade pages until they pick dark or system. Saved choices are untouched.
- Panel text is 1–2px larger; long Spanish labels wrap earlier. The viewport check (320–1440px, en/es, light/dark: sign-in, tenants list and view, users, profile) found no page overflow.
- The compact 30px option height applies only with a fine pointer on large screens; the 44px touch-target rule still holds on touch devices.
- Link-blue icons on `primary-subtle` in dark are 4.49:1: enough for icons (3:1), never use that pair for text.
- `canvas` / `raised` / `sunken` are new semantic tokens available to Blade pages too.
