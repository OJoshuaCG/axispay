# Accessibility

This page states the accessibility rules the design system enforces, the measured contrast of its token pairs, and the ARIA conventions each component follows. It is for anyone building or reviewing UI; the pre-merge checklist at the end is the short version.

## Quick rules

| Rule | Threshold / mechanism |
|---|---|
| Text contrast | ≥ 4.5:1 in both themes |
| UI contrast (control edges, focus ring, fills against the page) | ≥ 3:1 |
| Focus indicator | One global 2px solid outline; never restyle or remove it |
| Touch targets | ≥ 44px hit area for anything tappable, at every width (`sm` buttons extend theirs with `::before`) |
| Language | `<html lang>` follows the request locale; every user-facing string, including sr-only text, is translated |
| Reflow | No horizontal page scroll at 320px CSS width (WCAG 1.4.10); see [responsive.md](responsive.md) |
| Color | Never the only signal (text prefix, sign, icon plus label) |
| Motion | Honors `prefers-reduced-motion` globally |

## Contrast

### The one forbidden pairing

**`text-fg-muted` on `bg-surface-alt`** measures 4.35:1 in light theme, below 4.5:1. Use `text-fg-secondary` there instead. (`fg-muted` is for placeholders and non-essential metadata on `page` or `surface` only.)

### Measured ratios

WCAG 2.x relative-luminance ratios for the current token values, measured on 2026-09-23. Dark translucent tints (`*-subtle`) are composited over `page`. Re-measure whenever a primitive or semantic mapping changes.

**Text on surfaces**

| Pair | Light | Dark |
|---|---|---|
| `fg` on page / surface / surface-alt | 19.03 / 18.04 / 16.68 | 18.04 / 15.97 / 14.56 |
| `fg` on surface-pressed (ghost button active) | 14.25 | 11.99 |
| `fg-secondary` on page / surface / surface-alt | 5.78 / 5.48 / 5.06 | 10.33 / 9.14 / 8.34 |
| `fg-muted` on page / surface | 4.96 / 4.71 | 6.57 / 5.82 |
| `fg-muted` on surface-alt (forbidden) | **4.35 (fails)** | 5.31 |
| `accent` text on page | 5.28 | 7.18 |
| `link` / `link-visited` on page | 7.28 / 12.42 | 5.79 / 8.67 |

**Button labels (all white: `on-primary` / `on-accent` / `on-error`)**

| Fill | Default | Hover | Active |
|---|---|---|---|
| `primary` (light) | 7.28 | 9.61 | 12.42 |
| `primary` (dark) | 5.73 | 7.28 | 9.61 |
| `accent-fill` (both themes) | 5.28 | 7.80 | 11.64 |
| `error-fill` (both themes) | 6.47 | 8.31 | 10.02 |

Fills get darker on hover and active in both themes, which is why labels are always white.

**Status text**

| Token | On its `-subtle` bg over page (light / dark) | Over surface, dark | On page (light / dark) |
|---|---|---|---|
| `success` | 4.89 / 8.02 | 6.96 | 5.28 / 9.43 |
| `warning` | 4.58 / 8.45 | 7.42 | 5.02 / 9.51 |
| `error` | 5.56 / 5.50 | 4.91 | 6.47 / 5.91 |
| `info` | 6.52 / 7.76 | 6.73 | 7.28 / 8.67 |

Dark `info` is `blue-300` since 2026-09-23: with `blue-400` an info badge on a dark card (`surface`) measured **4.49:1**, below 4.5. In light theme the subtle backgrounds are opaque, so page and surface give the same ratios.

**Body text on alert backgrounds** (`fg` on `*-subtle`): ≥ 16.36 light, ≥ 15.34 dark over page, ≥ 13.32 dark over surface. Re-run `python3 tools/viewport-check/contrast.py` after changing a status or neutral token (it mirrors the token values; update them there too).

**Non-text UI (≥ 3:1)**

| Pair | Light | Dark |
|---|---|---|
| `line-strong` edge on page / surface | 3.47 / 3.29 | 3.83 / 3.39 |
| `focus-ring` on page / surface | 7.28 / 6.90 | 5.79 / 5.12 |
| `primary` fill against page | 7.28 | 3.32 |

Not measured: `line` hairlines (decorative, not required to meet 3:1). Brand `green-500` on white is 2.65:1 (per `semantic.css`), which is why it is decorative only.

## Focus

- `resources/css/base.css` defines the single indicator: `:where(:focus-visible) { outline: 2px solid var(--color-focus-ring); outline-offset: 2px; }`.
- It is an outline, not a box-shadow: it survives forced-colors mode and is never clipped.
- There is intentionally no focus utility. Components must not restyle it.
- Filament panels: `base.css` is not loaded there. The panel theme adds the same 2px `focus-ring` outline to Filament's sidebar items and group triggers, tabs, topbar items and the notifications button, inset (`outline-offset: -2px`) so the sidebar's scroll container does not clip it and the sticky topbar does not cover it (2.4.7, 2.4.11; ADR-0044). Filament's other controls keep their own focus rings.
- Exceptions in the code: `<x-input>` with affixes draws the same outline on its frame (`has-[input:focus-visible]:outline-focus-ring`) because the inner input uses `outline-none`; `<main>` uses `focus-visible:outline-none` because it only receives programmatic focus from the skip link.

## Touch targets

`--spacing-touch` is 2.75rem (44px). Verified at 320–1440px, both languages, both themes, with the viewport check ([responsive.md](responsive.md#viewport-check)).

| Component | Meets 44px |
|---|---|
| `<x-button size="md">` / `size="lg"` | Yes (`min-h-touch` / `min-h-12`) |
| `<x-button size="sm">` | Yes, hit area: looks 36px (`min-h-9`), a transparent `::before` with `-inset-1` makes the tappable area 44px tall. Keep ≥ 8px between adjacent `sm` buttons |
| Icon-only button | Yes: `md` `size-touch`, `lg` `size-12`, `sm` `size-9` + `::before` (44x44 hit area) |
| `<x-input>` | Yes (`min-h-touch`) |
| Theme toggle and language switcher options (`seg-option`) | Yes (44x44). On `desktop:` only (≥ 1024px and a fine pointer) they are 36x30, above the 24px minimum of 2.5.8; touch screens keep 44px |
| Inline prose links | Exempt (WCAG 2.5.8 inline exception) |

## ARIA conventions per component

| Component | Convention |
|---|---|
| `<x-input>` | `<label for>` always rendered (visually hidden with `hideLabel`). `aria-describedby` lists, in order: prefix id, suffix id, caller's own `aria-describedby`, hint id, error id. `aria-invalid="true"` only when `error` is set. Required asterisk is `aria-hidden`; the `required` attribute carries the meaning. |
| `<x-alert>` | Starts with a visually hidden, translated severity prefix (`ui.alert.*`: "Success:" / "Correcto:", "Warning:" / "Advertencia:", "Error:", "Information:" / "Información:"). `role="alert"` for error and warning, `role="status"` for success and info; override with `role="note"` for static notes. `focus` adds `tabindex="-1"` + `data-autofocus` so `focus.js` focuses it on load. |
| `<x-icon>` | Decorative by default: `aria-hidden="true"`. With `label`: `role="img"` + `aria-label`. Always `focusable="false"`. |
| `<x-button>` | Loading: keeps focus (no `disabled` attribute), sets `aria-disabled="true"`, `aria-busy="true"`, `data-loading`, and renders an sr-only `role="status"` with `loadingLabel` (default `ui.button.loading`: "Processing" / "Procesando"). Icon-only buttons require `label` (becomes `aria-label`); missing it follows the [invalid input policy](components.md#invalid-input-policy). A disabled link button drops `href` and gets `role="link"` + `aria-disabled="true"`. |
| `<x-theme-toggle>` | `role="radiogroup"` named by `ui.theme.label` ("Color theme" / "Tema de color"); options are `role="radio"` with `aria-checked`. Roving tabindex: Tab reaches the checked option, arrow keys move and select, Home/End jump to the ends. Option names are sr-only text unless `showLabels` (visible from `md` up). |
| `<x-language-switcher>` | Native `<form>` + submit buttons (no JS). `role="group"` named by `ui.language.label`; each button shows the code and is named "ES Español" (visible code first, native name in sr-only text, satisfying label-in-name 2.5.3) with `lang="<code>"`; current language `aria-pressed="true"`. |
| `<x-badge>` / `<x-payment-status>` | Text is the meaning; the icon is decorative. |
| `<x-amount>` | Sign character (`+`, U+2212 `−`) carries direction; color is secondary. Screen readers may not announce `+`, so add context in text where direction matters (e.g. a "Refund" label). |
| `<x-layouts.app>` | Translated skip link (`ui.layout.skip_to_content`) to `#main`, offset from notches (`top-edge start-edge`); `<main id="main" tabindex="-1">`; `lang` from the request locale. |

`resources/js/forms.js` blocks clicks, Enter/Space and implicit form submission on any element inside `[aria-disabled="true"]`, and `base.css` gives such elements `cursor: not-allowed`.

## Reduced motion

`base.css` sets, under `@media (prefers-reduced-motion: reduce)`, animation and transition durations to `0.01ms`, a single animation iteration, and `scroll-behavior: auto`, for all elements and pseudo-elements. You do not need `motion-safe:` for standard transitions; do not reintroduce long or looping motion with `!important`.

## Pre-merge checklist

- [ ] Only semantic color utilities; no `text-fg-muted` on `bg-surface-alt`.
- [ ] New color pairings measured against the thresholds above, in both themes.
- [ ] Every interactive element is keyboard reachable and shows the default focus outline.
- [ ] Tappable targets are ≥ 44px (no `size="sm"` for primary actions).
- [ ] Inputs use `<x-input>` with a real `label`; errors passed via `error`.
- [ ] Icon-only buttons have `label`; meaningful standalone icons have `label`.
- [ ] Alerts shown after a full-page submit use `focus`.
- [ ] Status and money never rely on color alone.
- [ ] Every user-facing string (including `label`, sr-only text and `title`) goes through `__()`.
- [ ] Checked at 320px in Spanish (longest strings): no horizontal scroll, no clipped text.
- [ ] Checked with keyboard only and in Light and Dark.
