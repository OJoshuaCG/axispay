# ADR-0030: Filament 5 panels themed from the design tokens, with a dark-mode bridge

- **Status:** Accepted (Phase 1, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** ADR-0025; master plan section 5; `docs/frontend/README.md`, `theming.md`

## Context

ADR-0025 chose Filament for the `admin` and `app` panels and required them to use the existing design tokens (Tailwind v4, Jost, light/dark, EN/ES). Three mismatches had to be solved:

1. Filament needs full 50–950 color scales and computes label contrast in PHP; our primitives have partial scales and no 950 step.
2. Filament switches dark mode with a `.dark` class on `<html>`; our semantic tokens use `light-dark()` driven by `color-scheme` / `data-theme`.
3. Filament loads fonts from Bunny Fonts by default; the project self-hosts Jost (no third-party font CDN).

## Options considered

- **Version:** Filament 4 (Livewire 3) or Filament 5 (Livewire 4). Filament 5.8.4 declares support for Laravel 13 and is the current stable line.
- **Colors:** (a) override Filament's `--primary-*` CSS variables in the theme; (b) register palettes in PHP built from `primitives.css`.
- **Dark mode:** (a) extend our token rules to also match `.dark`; (b) mirror Filament's `.dark` class into `data-theme`.

## Decision

- **Filament `^5.8`** (5.8.4), Livewire 4.4.
- **Colors (b):** `App\Support\Filament\DesignTokenPalette` parses the hex values of `resources/css/tokens/primitives.css` and registers Filament's `primary`, `info`, `success`, `gray`, `warning` and `danger` palettes. Missing steps map to the nearest existing primitive; the map is the class constant `MAP`, the single place to change it. Filament converts the hex to OKLCH, so its contrast math sees our real colors.
- **Theme CSS:** `resources/css/filament/theme.css` imports `tokens/primitives.css`, `tokens/semantic.css` and `theme.css` **before** Filament's theme. Our resets run first and our non-default `@theme` values win over Tailwind's `@theme default` palette, shadows, radius and type scale that Filament imports afterwards. `base.css` is not imported (Filament owns element defaults and focus rings).
- **Font:** `App\Support\Filament\ViteFontProvider` prints `Vite::fonts()` (same output as `@fonts`), and the theme maps `--font-sans` to `--font-jost`.
  - *Update 2026-09-25 ([ADR-0042](0042-typography-mukta-geist-mono.md)):* Jost was replaced by Mukta (text) and Geist Mono (currency and numeric data). The provider is unchanged; the theme now maps `--font-sans` to `--font-mukta` and `--font-mono` to `--font-numeric`.
- **Dark mode (b):** a `HEAD_END` render hook (`resources/views/filament/partials/theme-bridge.blade.php`) sets `data-theme` from the `.dark` class before first paint and keeps it in sync with a `MutationObserver`. Filament and `resources/js/theme.js` already share the `localStorage` key `theme` and its values `light | dark | system`, so a choice made on one surface applies to the other on the same origin.
- **Touch targets:** the theme raises Filament buttons, inputs and sidebar items to 44px and form controls to 16px text. Filament's 32px icon buttons, sort buttons, row links, breadcrumbs, checkboxes and the pagination select are documented exceptions (`docs/frontend/theming.md`).

## Rationale

Registering palettes in PHP keeps one copy of every color value and keeps Filament's automatic label-contrast selection correct. Mirroring the class is a few lines and does not touch the token files; extending the token rules to `.dark` would still disagree when the user picks "light" while the OS is dark.

## Consequences

- Changing a primitive in `primitives.css` updates the panels after `pnpm run build` (CSS) and immediately in PHP.
- `php artisan filament:upgrade` runs on `post-autoload-dump` and publishes Filament's JS/CSS into `public/{js,css,fonts}/filament` (gitignored).
- The panel theme is a separate Vite entry (`vite.config.js`).
