# Design tokens

This page lists every design token, where it is defined, and the utility it produces. It is for developers who need to pick the right utility, understand how a value flows from a hex to a class, or add a new token. For light/dark mechanics see [theming.md](theming.md); for contrast data see [accessibility.md](accessibility.md).

## Quick answer

- In views, use the **semantic** utilities: `bg-page`, `bg-surface`, `text-fg`, `text-fg-secondary`, `border-line`, `bg-primary`, `text-error`, and so on.
- Primitive utilities (`bg-blue-500`, `text-neutral-400`) exist for the preview page and rare decorative needs. They never carry meaning and do not switch with the theme.
- Anything not listed on this page for a reset namespace does not exist as a utility.

## Architecture

```
primitives.css          semantic.css                          theme.css
--color-blue-600  --->  --color-primary: light-dark(          @theme: spacing, type, radius,
--color-blue-500        var(--color-blue-600),                 icons, motion, z-index, opacity
                        var(--color-blue-500))
        \                        |                                     |
         \-----------------------+------------> Tailwind utilities <---/
                                                bg-primary, text-fg, p-inset-md, z-modal ...
```

| Layer | File | What it holds |
|---|---|---|
| Primitives | `resources/css/tokens/primitives.css` | Raw color scales (hex). The only literal colors, apart from a few dark translucent tints and one hairline value in `semantic.css`. |
| Semantic | `resources/css/tokens/semantic.css` | Role colors defined once under their final Tailwind name as `light-dark(<light>, <dark>)`, plus shadows. |
| Theme | `resources/css/theme.css` | Non-color scales (type, spacing, radius, icons, motion, layers, opacity), the Tailwind mappings for motion and z-index, and the `dark:` variant. |

All three use `@theme static`, so every variable is emitted to CSS and plain CSS can read it with `var(--color-page)` even if no utility uses it. The motion and z-index mappings use `@theme inline`, so their utilities reference the raw token directly (for example `transition-duration: var(--duration-fast)`).

### Tailwind defaults that are reset

These namespaces are cleared with `initial`, so only the tokens on this page exist:

| Namespace | Reset in | Effect |
|---|---|---|
| `--color-*` | `primitives.css` | No `white`, `black`, `gray-*`, `slate-*`... Only the scales below. |
| `--shadow-*` | `semantic.css` | Only `shadow-xs` ... `shadow-xl`. |
| `--text-*` | `theme.css` | Only `text-xs` ... `text-5xl`. |
| `--font-weight-*` | `theme.css` | Only `font-normal`, `font-medium`, `font-semibold`, `font-bold`. |
| `--tracking-*` | `theme.css` | Only the five tracking values below. |
| `--radius-*` | `theme.css` | Only `rounded-xs` ... `rounded-full`. |
| `--ease-*` | `theme.css` | Only `ease-standard`, `ease-emphasized`, `ease-out`. |

Not reset, so Tailwind defaults still apply: the numeric spacing scale (`p-4`, `gap-2`, multiples of `--spacing`), breakpoints (`sm:` 40rem, `md:` 48rem, `lg:` 64rem, `xl:` 80rem; the contract of what changes at each is in [responsive.md](responsive.md#breakpoint-contract) and at the top of `theme.css`), default container widths, `animate-*`, and other namespaces not listed above. Prefer the named tokens below over numeric spacing when one fits.

## Color primitives

Defined in `primitives.css`. Utilities: `bg-*`, `text-*`, `border-*`, etc. (e.g. `bg-blue-500`).

| Scale | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|---|
| blue | `#edf3fb` | `#dbe6f6` | `#b7cded` | `#8fb1e3` | `#5e8fd7` | `#1f63c7` | `#1a54a9` | `#154387` | `#103367` | `#0b2448` |
| green | `#edf9f4` | `#dcf3e9` | `#b8e7d2` | `#90dab9` | `#60ca9a` | `#22b573` | `#1d9a62` | `#177b4e` | `#125e3c` | `#0c4129` |

Neutral has extra steps:

| neutral step | 0 | 50 | 100 | 200 | 300 | 400 | 450 | 500 | 550 | 600 | 700 | 800 | 900 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| value | `#ffffff` | `#f8f9f9` | `#eff0f1` | `#dddfe2` | `#bcbfc5` | `#9398a2` | `#858a94` | `#697080` | `#5f6673` | `#1c2536` | `#171d2b` | `#111620` | `#0d1017` |

`neutral-450` (3:1 control edges on light) and `neutral-550` (secondary text on light) were added; `neutral-500` was retuned. Note the jump in lightness between 550 and 600: 600–900 are the dark-theme surfaces.

Status hues are partial scales (only the values in use):

| Hue | Steps |
|---|---|
| amber | 50 `#fdf3e7`, 400 `#f0a94e`, 700 `#b45309` |
| red | 50 `#fbeaea`, 400 `#e5695f`, 700 `#b91c1c`, 800 `#991b1b`, 900 `#7f1d1d` |

## Semantic colors

Defined in `semantic.css`. Each token `--color-<name>` produces `bg-<name>`, `text-<name>`, `border-<name>`, `ring-<name>`, `outline-<name>`, etc. "Same" means the value does not change between themes.

### Surfaces and borders

| Token | Light | Dark | Use |
|---|---|---|---|
| `page` | neutral-0 | neutral-900 | Page background |
| `surface` | neutral-50 | neutral-700 | Cards, raised areas |
| `surface-alt` | neutral-100 | neutral-600 | Hover fills, neutral badges, disabled fields |
| `surface-pressed` | neutral-200 | `#2a3346` (same hairline value as dark `line`) | Pressed state of low-emphasis controls (ghost button `:active`) |
| `line` | neutral-200 | `#2a3346` (one-off) | Hairlines, dividers, card borders |
| `line-strong` | neutral-450 | neutral-500 | Input and control edges (3:1) |

### Text

| Token | Light | Dark | Use |
|---|---|---|---|
| `fg` | neutral-900 | neutral-50 | Body text |
| `fg-secondary` | neutral-550 | neutral-300 | Secondary copy, hints, affixes |
| `fg-muted` | neutral-500 | neutral-400 | Placeholders, non-essential metadata; page/surface only |
| `on-primary` | neutral-0 | same | Label on `primary` |
| `on-accent` | neutral-0 | same | Label on `accent-fill` |
| `on-error` | neutral-0 | same | Label on `error-fill` (danger button) |

### Brand

| Token | Light | Dark | Use |
|---|---|---|---|
| `primary` | blue-600 | blue-500 | Main action fill, brand emphasis |
| `primary-hover` | blue-700 | blue-600 | Hover |
| `primary-active` | blue-800 | blue-700 | Pressed |
| `primary-subtle` | blue-50 | `rgb(31 99 199 / 0.15)` | Tinted background |
| `accent` | green-700 | green-500 | Accent text and icons |
| `accent-fill` | green-700 | same | Accent button fill |
| `accent-hover` | green-800 | same | Hover |
| `accent-active` | green-900 | same | Pressed |
| `accent-subtle` | green-50 | `rgb(34 181 115 / 0.15)` | Tinted background |
| `feature` | blue-600 | green-500 | Decorative brand color only |
| `feature-subtle` | blue-50 | `rgb(34 181 115 / 0.12)` | Decorative background |

### Status

| Token | Light | Dark | Use |
|---|---|---|---|
| `success` | green-700 | green-400 | Success text, icons, borders |
| `success-subtle` | green-50 | `rgb(34 181 115 / 0.12)` | Success background |
| `warning` | amber-700 | amber-400 | Warning text, icons, borders |
| `warning-subtle` | amber-50 | `rgb(180 83 9 / 0.15)` | Warning background |
| `error` | red-700 | red-400 | Error text and icons |
| `error-subtle` | red-50 | `rgb(185 28 28 / 0.15)` | Error background |
| `error-fill` | red-700 | same | Solid destructive control |
| `error-fill-hover` | red-800 | same | Hover |
| `error-fill-active` | red-900 | same | Pressed |
| `info` | blue-600 | blue-300 | Info text, icons, borders (dark raised from blue-400 for 4.5:1 on a dark card, see [accessibility.md](accessibility.md#measured-ratios)) |
| `info-subtle` | blue-50 | `rgb(31 99 199 / 0.15)` | Info background |

### Money, links, focus

| Token | Light | Dark | Use |
|---|---|---|---|
| `amount-positive` | = `success` | = `success` | Positive signed amounts |
| `amount-negative` | = `error` | = `error` | Negative signed amounts |
| `link` | blue-600 | blue-400 | Links (applied globally in `base.css`) |
| `link-visited` | blue-800 | blue-300 | Visited links |
| `focus-ring` | blue-600 | blue-400 | The global focus outline |

## Spacing and layout

Defined in `theme.css`. `--spacing: 0.25rem` keeps Tailwind's numeric scale (`p-4` = 1rem).

| Token | Value | Example utility | Use |
|---|---|---|---|
| `--spacing-stack-xs` | 0.25rem | `gap-stack-xs` | Vertical rhythm between related items |
| `--spacing-stack-sm` | 0.5rem | `gap-stack-sm` | |
| `--spacing-stack-md` | 1rem | `gap-stack-md` | |
| `--spacing-stack-lg` | 1.5rem | `gap-stack-lg` | |
| `--spacing-stack-xl` | 2.5rem | `gap-stack-xl` | |
| `--spacing-inset-sm` | 0.75rem | `p-inset-sm` | Inner padding of containers |
| `--spacing-inset-md` | 1rem | `p-inset-md` | |
| `--spacing-inset-lg` | 1.5rem | `p-inset-lg` | |
| `--spacing-gutter` | `max(clamp(1rem, 0.5rem + 2.5vw, 2.5rem), env(safe-area-inset-left/right))` | `px-gutter` | Fluid page side padding, never under a notch |
| `--spacing-safe-top` / `--spacing-safe-bottom` | `env(safe-area-inset-top/bottom, 0px)` | `pt-safe-top`, `pb-safe-bottom` | Sticky headers, page top/bottom on notched devices |
| `--spacing-edge` | `max(1rem, env(safe-area-inset-top), env(safe-area-inset-left))` | `top-edge`, `start-edge` | Offset of fixed overlays (skip link, future toasts) |
| `--spacing-section` | `clamp(3rem, 2rem + 5vw, 6rem)` | `gap-section`, `py-section` | Fluid space between page sections |
| `--spacing-touch` | 2.75rem (44px) | `min-h-touch`, `size-touch` | Minimum interactive target |
| `--container-narrow` | 40rem | `max-w-narrow` | Readable text column, forms |
| `--container-content` | 72rem | `max-w-content` | Main content |
| `--container-wide` | 90rem | `max-w-wide` | Full-width shells |

## Typography

Font families (ADR-0042):

| Utility | Token | Face | Use |
|---|---|---|---|
| `font-sans` (default) | `--font-sans` | Mukta, then system sans-serif | All text |
| `font-numeric` | `--font-numeric` | Geist Mono, then `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` | Currency amounts and numeric money data. Applied by `<x-amount>` and the `amount` utility; do not add it by hand where those fit |
| `font-mono` | `--font-mono` | Geist Mono, then the system monospace stack | Code, keys, IDs, token names. Never for money |

`--font-mukta` and `--font-geist-mono` are emitted by the `@fonts` directive in the layout (and by `ViteFontProvider` in the panels). `vite.config.js` loads Mukta 400/500/600/700 and Geist Mono 400/600 from the pinned `@fontsource/*` packages, one latin-subset WOFF2 file per weight (latin covers English, Spanish, `€` and U+2212 MINUS SIGN), `display: swap`, preloading Mukta 400 and 600 only. It uses the plugin's `local()` provider on those files, not `fontsource()`: in laravel-vite-plugin 3.2.0 `fontsource()` emits the woff2 and woff of a weight as two rules with identical descriptors, the woff wins, and browsers downloaded both (see the comment in `vite.config.js`). Mukta has `optimizedFallbacks: true`: `fontaine` (devDependency, optional peer of `laravel-vite-plugin`) emits a metric-matched `"Mukta fallback"` face (local Arial with ascent/descent/line-gap/size-adjust overrides) so the font swap barely shifts layout. Removing `fontaine` silently disables it (the plugin only warns). Geist Mono has it off: fontaine does not detect it as monospace and would put a scaled Arial in front of the monospace stack; the system monospace fonts already share its 0.6em digit width.

The type scale below was kept when Mukta replaced Jost: every size sets an explicit line height, so Mukta's tall ascent/descent (1.13/0.53em, sized for Devanagari) do not change the line box. Its Latin x-height is 0.468em and cap height 0.63em (Geist Mono: 0.53em / 0.71em). Checked on `/design-system` from 320 to 1440px in both languages.

| Utility | Size | Line height | Letter spacing |
|---|---|---|---|
| `text-xs` | 0.75rem | 1rem | — |
| `text-sm` | 0.875rem | 1.25rem | — |
| `text-base` | 1rem | 1.5rem | — |
| `text-lg` | 1.125rem | 1.75rem | — |
| `text-xl` | 1.25rem | 1.75rem | — |
| `text-2xl` | 1.5rem | 2rem | -0.01em |
| `text-3xl` | `clamp(1.75rem, 1.45rem + 1.25vw, 2.25rem)` | 1.2 | -0.015em |
| `text-4xl` | `clamp(2.125rem, 1.6rem + 2.2vw, 3rem)` | 1.15 | -0.02em |
| `text-5xl` | `clamp(2.5rem, 1.75rem + 3.2vw, 3.75rem)` | 1.1 | -0.025em |

| Weights | Tracking |
|---|---|
| `font-normal` 400, `font-medium` 500, `font-semibold` 600, `font-bold` 700 | `tracking-tight` -0.02em, `tracking-snug` -0.01em, `tracking-normal` 0em, `tracking-wide` 0.02em, `tracking-wider` 0.06em |

Only the four loaded Mukta weights exist; there is no `font-light` or `font-black`. Geist Mono loads 400 and 600 only: on amounts, `font-medium` renders 400 and `font-bold` renders 600.

## Radius

| Utility | Value |
|---|---|
| `rounded-xs` | 0.125rem |
| `rounded-sm` | 0.25rem |
| `rounded-md` | 0.5rem (buttons, inputs, alerts) |
| `rounded-lg` | 0.75rem (cards) |
| `rounded-xl` | 1rem |
| `rounded-2xl` | 1.5rem |
| `rounded-full` | 9999px (badges, theme toggle) |

## Shadows

Defined in `semantic.css` because the shadow color changes with the theme.

| Utility | Geometry | Light color | Dark color |
|---|---|---|---|
| `shadow-xs` | `0 1px 1px` | `rgb(13 16 23 / 0.04)` | `rgb(0 0 0 / 0.25)` |
| `shadow-sm` | `0 1px 2px` | `rgb(13 16 23 / 0.06)` | `rgb(0 0 0 / 0.3)` |
| `shadow-md` | `0 4px 12px` | `rgb(13 16 23 / 0.1)` | `rgb(0 0 0 / 0.4)` |
| `shadow-lg` | `0 8px 24px` | `rgb(13 16 23 / 0.12)` | `rgb(0 0 0 / 0.45)` |
| `shadow-xl` | `0 16px 40px` | `rgb(13 16 23 / 0.16)` | `rgb(0 0 0 / 0.5)` |

## Motion

| Token | Value | Utility |
|---|---|---|
| `--duration-fast` | 120ms | `duration-fast` |
| `--duration-base` | 200ms | `duration-base` (also the default transition duration) |
| `--duration-slow` | 320ms | `duration-slow` |
| `--ease-standard` | `cubic-bezier(0.4, 0, 0.2, 1)` | `ease-standard` (also the default timing function) |
| `--ease-emphasized` | `cubic-bezier(0.2, 0, 0, 1)` | `ease-emphasized` (no overshoot) |
| `--ease-out` | `cubic-bezier(0, 0, 0.2, 1)` | `ease-out` |

Components use `transition-colors duration-fast ease-standard`. Reduced-motion handling is global; see [accessibility.md](accessibility.md#reduced-motion).

## Z-index

| Token | Value | Utility |
|---|---|---|
| `--z-dropdown` | 1000 | `z-dropdown` |
| `--z-sticky` | 1100 | `z-sticky` |
| `--z-overlay` | 1200 | `z-overlay` |
| `--z-modal` | 1300 | `z-modal` |
| `--z-popover` | 1400 | `z-popover` |
| `--z-toast` | 1500 | `z-toast` |
| `--z-tooltip` | 1600 | `z-tooltip` |

Use the named layers instead of numeric `z-*` values.

## States, touch and icon sizes

| Token | Value | Utility |
|---|---|---|
| `--opacity-disabled` | 0.5 | `opacity-disabled` |
| `--spacing-touch` | 2.75rem | `min-h-touch`, `min-w-touch`, `size-touch` |
| `--size-icon-xs` | 0.875rem | `size-icon-xs` |
| `--size-icon-sm` | 1rem | `size-icon-sm` |
| `--size-icon-md` | 1.25rem | `size-icon-md` |
| `--size-icon-lg` | 1.5rem | `size-icon-lg` |
| `--size-icon-xl` | 2rem | `size-icon-xl` |

## Naming conventions

| Pattern | Meaning | Example |
|---|---|---|
| `fg`, `fg-secondary`, `fg-muted` | Foreground (text) colors | `text-fg` |
| `line`, `line-strong` | Border colors | `border-line-strong` |
| `on-*` | Label color on a solid fill | `text-on-primary` on `bg-primary` |
| `*-fill` | Solid control fill that carries a white label | `bg-error-fill` |
| `*-hover`, `*-active` | Interaction states of a fill | `hover:bg-primary-hover` |
| `*-subtle` | Tinted background; **never text** | `bg-success-subtle` |
| `stack-*` / `inset-*` | Gaps between items / padding inside containers | `gap-stack-sm`, `p-inset-md` |
| Primitive `<hue>-<step>` | Raw scale value, no meaning | `bg-blue-50` |

Note the split between `error` (text and icons) and `error-fill` (solid destructive controls), and between `accent` (text) and `accent-fill` (button fill): the text token changes with the theme, the fill does not.

## Adding a token

1. **Decide the layer.** A new raw color → `primitives.css`. A new role → `semantic.css`. A non-color scale value → `theme.css`.
2. **Reuse before adding.** Check the tables above; a new token must name a role that no existing token covers.
3. **Name it under its final Tailwind namespace** (`--color-<role>`, `--spacing-<name>`, `--radius-<name>`...) so the utility appears automatically.
4. **Colors: define both themes** with `light-dark(var(--color-<light>), var(--color-<dark>))`, referencing primitives. Use a literal only for a dark translucent tint.
5. **Check contrast** for every text or UI pairing in both themes against the thresholds in [accessibility.md](accessibility.md#contrast).
6. **Add it to the preview.** Add a swatch or sample to `resources/views/design-system.blade.php`, written as a literal class.
7. **Update this page.**

If a token needs its own utility mapping (as motion and z-index do), add it to the `@theme inline` block in `theme.css`.
