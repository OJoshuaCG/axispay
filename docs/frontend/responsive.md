# Responsive: mobile-first, 320px and up

Every page works from a 320px-wide phone to a 1440px+ desktop, in English and Spanish, light and dark: no horizontal page scroll, no clipped text, 44px touch targets. Write the 320px layout first with unprefixed classes, then add breakpoint prefixes only for what changes upward. This page is for anyone writing a view or a component.

## Quick path

1. Build the phone layout with unprefixed utilities (single column, wrapping rows).
2. Add `sm:` / `md:` / `lg:` only where the [breakpoint contract](#breakpoint-contract) says layout changes.
3. Check `?lang=es` at 320px in both themes (Spanish strings are the longest), then run the [viewport check](#viewport-check).

## Breakpoint contract

Tailwind's default breakpoints are kept: they match common device classes and every Tailwind reader knows them. Adding one needs a documented reason in `theme.css`.

| Range | Prefix | What changes |
|---|---|---|
| 0–639px | (none) | Single column. Header controls wrap to their own line. Theme toggle icon-only. Language switcher shows codes ("EN"/"ES"). Button rows wrap; row labels take a full line |
| ≥ 640px (40rem) | `sm:` | Native language names. 2-column form rows allowed. Row labels go inline (`sm:w-24`) |
| ≥ 768px (48rem) | `md:` | Theme labels (when `theme-labels`). 2–3 column grids (inputs, cards) |
| ≥ 1024px (64rem) | `lg:` | Wide grids (swatches, icons). Side-by-side sections |
| ≥ 1280px (80rem) | `xl:` | Nothing new: only `max-w-*` containers stop growing |

The same table is a comment at the top of `resources/css/theme.css`.

## Rules

| Area | Rule | How |
|---|---|---|
| Page scroll | Never horizontal at ≥ 320px | Containers `w-full` + `max-w-*` + `px-gutter`; no fixed widths wider than 288px on phones |
| Flex rows | Wrap, and let text shrink | `flex-wrap`; text children get `min-w-0` |
| Long text | Wrap or truncate, never overflow | `break-words` for prose and labels, `break-all` for code-like identifiers, `truncate` only where the full text is available elsewhere |
| Grids next to amounts | Let the label column shrink | `grid-cols-[minmax(0,1fr)_auto]`, not `[1fr_auto]` |
| Tables / wide grids | Scroll inside their own container, never the page | Wrap in `<div class="overflow-x-auto">`; or reflow to cards below `md` |
| Buttons | Labels wrap (the component does this); keep `gap-2` between `sm` buttons | Do not add `whitespace-nowrap` |
| Touch targets | ≥ 44x44px hit area at every width | `min-h-touch min-w-touch`; `sm` buttons extend theirs with `::before` |
| Inputs | Font size ≥ 16px, so iOS Safari does not zoom on focus | `<x-input>` is always `text-base`; never pass `text-sm` to a control |
| Height | `min-h-dvh`, never `h-screen` / `100vh` (mobile browser bars) | Layout and pages already use it |
| Media | Never wider than the container | `max-w-full` on images and SVG (`<x-icon>` has it) |
| Safe areas | Content never under a notch or home indicator | See below |

### Safe areas

The layout sets `viewport-fit=cover`, so `env(safe-area-inset-*)` is non-zero on notched devices. Tokens, not arbitrary values:

| Need | Utility |
|---|---|
| Page side padding | `px-gutter` (already `max(fluid gutter, safe-area-inset-left/right)`) |
| Sticky header top | `pt-safe-top` on the sticky element |
| Top/bottom of a page shell | `pt-safe-top` / `pb-safe-bottom` on the outer container |
| Fixed overlay offset (skip link, toasts) | `top-edge`, `start-edge` |

### Header pattern

```blade
<header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-stack-lg">
    <span class="min-w-0 truncate font-semibold">{{ config('app.name') }}</span>
    <x-site-controls />
</header>
```

At 320px the brand stays on line one and the controls move to line two; from about 480px everything fits on one line.

## Viewport check

An optional browser script in `tools/viewport-check/` loads pages in `en`/`es` × light/dark × 320, 375, 414, 768, 1024, 1280, 1440px and reports:

- `document.documentElement.scrollWidth > innerWidth` (page scroll);
- elements escaping the viewport, and elements whose content is wider than their box without scrolling or ellipsis;
- interactive elements smaller than 44x44 (counting a `::before` hit area; inline prose links exempt);
- inputs under 16px; JavaScript errors.

```sh
php artisan serve --port=8000        # in another terminal
cd tools/viewport-check
npm install                          # playwright, isolated from the app
npx playwright install chromium      # ~115 MB headless browser, cached in ~/.cache/ms-playwright
BASE_URL=http://127.0.0.1:8000 node check.mjs --shots ./shots
```

`PATHS=/,/checkout` limits the pages. Exit code 1 when anything is found. `python3 tools/viewport-check/contrast.py` recomputes status-color contrast (see [accessibility.md](accessibility.md#measured-ratios)).

**Why it is not in the root `package.json`:** Playwright downloads a browser and is not needed to build or run the app. It lives in its own folder with its own `package.json` (`node_modules` and `shots` are gitignored, and `app.css` excludes `tools/` from Tailwind's scan). It is a manual check, not a test suite, and nothing runs it automatically.

Last run (2026-09-23): 56 page/locale/theme/width combinations for `/` and `/design-system`, **0 issues**, no JS errors. Known false positive handled by the script: the `sm` button `::before` hit area is not counted as overflowing content.

## Checklist

- [ ] Written mobile-first: unprefixed classes are the 320px layout.
- [ ] Checked at 320px in Spanish, both themes: no horizontal scroll, no clipped text.
- [ ] Flex rows wrap; text children have `min-w-0`; long identifiers use `break-all`.
- [ ] Tables and wide content scroll inside their own container.
- [ ] Tappable targets ≥ 44px; inputs 16px.
- [ ] Sticky/fixed elements use the safe-area utilities.
