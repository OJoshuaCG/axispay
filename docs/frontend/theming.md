# Theming: light and dark

This page explains how the light, dark and system themes work, how the preference is stored and applied, and what the theming system cannot do. It is for developers who build pages, touch the theme toggle, or are tempted to write `dark:` classes.

## Quick answer

- You normally write **no** theme code. Semantic utilities (`bg-page`, `text-fg`, `border-line`...) switch automatically.
- The theme is set with `data-theme` on `<html>`: `light`, `dark`, or absent (follow the OS).
- Theming is **root-level only**. A `data-theme="dark"` on a nested element does not re-theme that section.

## How it works

| Piece | Where | What it does |
|---|---|---|
| `color-scheme` | `resources/css/tokens/semantic.css` | `:root { color-scheme: light dark }`, overridden to `light` / `dark` by `:root[data-theme=...]` |
| `light-dark()` | `semantic.css` | Every semantic color is `light-dark(<light>, <dark>)` and resolves against `color-scheme` |
| Pre-paint script | `resources/views/components/layouts/app.blade.php` | Reads `localStorage` and sets `data-theme` before first paint |
| Toggle logic | `resources/js/theme.js` | Syncs toggles, handles keyboard, saves the preference, syncs other tabs |
| Toggle UI | `resources/views/components/theme-toggle.blade.php` | Light / Dark / System radio group |

### Theme states

| Preference | `<html>` attribute | `localStorage['theme']` | Effective theme |
|---|---|---|---|
| Light | `data-theme="light"` | `light` | Light |
| Dark | `data-theme="dark"` | `dark` | Dark |
| System | no `data-theme` | `system` | OS preference (`prefers-color-scheme`) |

- Storage key: `theme` (`STORAGE_KEY` in `theme.js`). Allowed values: `light`, `dark`, `system` (`THEMES`). Any other or missing value is read as `system`.
- If storage is unavailable (private mode, blocked storage), reads fall back to `system` and saves are skipped; the chosen theme still applies to the current page.
- A `storage` event listener keeps other open tabs in sync.

### No flash on load

The layout's `<head>` runs an inline script before CSS and fonts load:

```html
<script>
    (function () {
        try {
            var theme = window.localStorage.getItem('theme');
            if (theme === 'light' || theme === 'dark') {
                document.documentElement.setAttribute('data-theme', theme);
            }
        } catch (error) {}
    })();
</script>
```

It carries the Vite CSP nonce when one is set. **Keep it in sync with `theme.js`** (same key, same values) if either changes.

The toggle has nothing checked in the server-rendered HTML, because the server cannot know the saved preference. `theme.js` marks the correct option when it runs; until then the first option is the tab stop.

## The `dark:` variant

Defined in `resources/css/theme.css`:

```css
@custom-variant dark {
    &:where([data-theme='dark'], [data-theme='dark'] *) {
        @slot;
    }

    @media (prefers-color-scheme: dark) {
        &:where(:root:not([data-theme='light']), :root:not([data-theme='light']) *) {
            @slot;
        }
    }
}
```

`dark:` matches exactly when the effective theme is dark: manual dark, or OS dark with no manual light override.

### When to use `dark:`

Rarely. No view or component uses it today.

| Situation | Use |
|---|---|
| A color that should differ per theme | A semantic token (existing, or a new one in `semantic.css`) |
| A non-color tweak that only makes sense in dark (e.g. hide a light-only illustration, swap an image) | `dark:` is acceptable |
| A one-off color override in a view | Not allowed; add or reuse a token |

## Limitation: root-level only

Nested theming does **not** work:

```html
<!-- Does NOT produce a dark section -->
<section data-theme="dark" class="bg-page text-fg">...</section>
```

**Why.** At build time, Lightning CSS compiles `light-dark()` into custom-property switches (`--lightningcss-light` / `--lightningcss-dark`) that only the `:root` rules set. Each token is resolved once on `:root` and then inherited as a value, so a nested `data-theme` changes nothing. (The `dark:` variant would match inside such a section, but tokens would not follow, so mixing them produces inconsistent results.)

**Fix options, if scoped dark sections are ever needed** (from the note in `semantic.css`):

1. Redeclare the semantic tokens under the scoped selector, so they resolve again inside it.
2. Raise the build target so `light-dark()` ships untranspiled to the browser.

Neither is implemented. Do not set an inline `color-scheme` either; set `data-theme` on `<html>` only.

## Browser UI color (`theme-color`)

The browser chrome follows the **effective** theme, manual choice included.

| Situation | Who sets it | Value |
|---|---|---|
| Server render / System | Layout `<meta>` tags, one per `prefers-color-scheme` | `#ffffff` / `#0d1017` |
| Saved manual theme, before first paint | Pre-paint script copies the chosen scheme's `content` into both tags (keeps the original in `data-default-content`) | Server hex of that scheme |
| Manual theme, after `theme.js` runs or on change | `syncThemeColor()` in `theme.js` | Computed `--color-page` of `<html>` (e.g. `rgb(13, 16, 23)`) |
| Back to System | `theme.js` restores the server values | `#ffffff` / `#0d1017` |

```html
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)" data-theme-color="light">
<meta name="theme-color" content="#0d1017" media="(prefers-color-scheme: dark)" data-theme-color="dark">
```

The two hexes are the only copy of `page` outside the tokens: they are needed before any CSS loads. If `page` changes in `primitives.css` / `semantic.css`, update them in `components/layouts/app.blade.php`. `theme.js` hardcodes no color.

## Theme toggle labels

The toggle's labels (`ui.theme.light|dark|system`) and group name (`ui.theme.label`) are translated server-side. `theme.js` never writes user-facing text, so no JS change is needed per language.

## Checklist

- [ ] No hex, `rgb()` or arbitrary color in the view; semantic utilities only.
- [ ] Checked in Light, Dark and System on `/design-system` or the target page.
- [ ] No nested `data-theme` and no inline `color-scheme`.
- [ ] Any `dark:` usage is non-color, or is justified in review.
- [ ] If the storage key or values changed, the pre-paint script and `theme.js` still match.
- [ ] Browser chrome color matches the page after switching Light / Dark / System (mobile browsers).
