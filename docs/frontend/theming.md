# Theming: light and dark

This page explains how the light, dark and system themes work, how the preference is stored and applied, and what the theming system cannot do. It is for developers who build pages, touch the theme toggle, or are tempted to write `dark:` classes.

## Quick answer

- You normally write **no** theme code. Semantic utilities (`bg-page`, `text-fg`, `border-line`...) switch automatically.
- The theme is set with `data-theme` on `<html>`: `light`, `dark`, or absent (follow the OS).
- **Default: light.** With no saved preference (or unreadable storage), pages and panels are light; only an explicit "System" follows the OS (ADR-0044).
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
| Light (default) | `data-theme="light"` | `light` or nothing saved | Light |
| Dark | `data-theme="dark"` | `dark` | Dark |
| System | no `data-theme` | `system` | OS preference (`prefers-color-scheme`) |

- Storage key: `theme` (`STORAGE_KEY` in `theme.js`). Allowed values: `light`, `dark`, `system` (`THEMES`). Any other or missing value is read as `light` (`DEFAULT_THEME`).
- If storage is unavailable (private mode, blocked storage), reads fall back to `light` and saves are skipped; the chosen theme still applies to the current page.
- A `storage` event listener keeps other open tabs in sync.

### No flash on load

The layout's `<head>` runs an inline script before CSS and fonts load:

```html
<script>
    (function () {
        var theme = null;
        try {
            theme = window.localStorage.getItem('theme');
        } catch (error) {}
        if (theme !== 'dark' && theme !== 'system') {
            theme = 'light';
        }
        if (theme === 'system') {
            return;
        }
        document.documentElement.setAttribute('data-theme', theme);
        // ...then copies the chosen scheme's theme-color into both meta tags.
    })();
</script>
```

It carries the Vite CSP nonce when one is set. **Keep it in sync with `theme.js`** (same key, same values, same default) if either changes.

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

## Filament panels (admin and app)

The panels use the same tokens through a separate Vite entry, `resources/css/filament/theme.css` (ADR-0030).

| Piece | Where | What it does |
|---|---|---|
| Token import | `resources/css/filament/theme.css` | Imports `primitives.css`, `semantic.css` and `theme.css` **before** Filament's theme, so our values win over Tailwind's defaults (palette, shadows, radius, type scale). `base.css` is not imported. |
| Filament palettes | `app/Support/Filament/DesignTokenPalette.php` | Registers Filament's `primary`, `info`, `success`, `gray`, `warning`, `danger` scales from the hex values in `primitives.css` (missing steps map to the nearest primitive; see `MAP`). No color is copied. |
| Font | `app/Support/Filament/ViteFontProvider.php` | Self-hosted Mukta and Geist Mono (`Vite::fonts()`); the panel theme maps `--font-sans` → `--font-mukta` and `--font-mono` → `--font-numeric` (Geist Mono), so `->fontFamily(FontFamily::Mono)` is the money-column convention ([payments-ui.md](payments-ui.md#filament-panels)). |
| Dark-mode bridge | `resources/views/filament/partials/theme-bridge.blade.php` (render hook `HEAD_END`) | Mirrors Filament's `.dark` class on `<html>` into `data-theme="dark|light"` before first paint and on every change (`MutationObserver`). |
| Default mode | `app/Support/Filament/PanelDefaults.php` | `->defaultThemeMode(ThemeMode::Light)`: light when nothing is saved (ADR-0044). |
| Theme control | `resources/views/filament/partials/theme-control.blade.php` | Light / Dark / System segmented group (`aria-pressed` buttons). On the sign-in pages (with the language switcher, `guest-controls`) and in the topbar from `md` up (`panel-controls`). |
| Panel chrome | `resources/views/filament/**`, `.pl-*` classes in the panel theme | Language switcher, test/live badge, banners; semantic tokens only. |

### How the two theme systems agree

Filament stores the preference in `localStorage['theme']` with the values `light`, `dark` and `system` — the same key and values as `resources/js/theme.js`. Filament's own head script turns that into the `.dark` class; the bridge then sets `data-theme`, which drives `color-scheme` and every `light-dark()` token. Filament's component styles use its `.dark` variant (it wins in the panel build), so both halves always follow the same effective theme, including "System" and OS changes. There is no flash: both scripts run in `<head>` before the body renders.

### Panel theme control

The control does not store anything itself. A click dispatches Filament's `theme-changed` window event; Filament's panel JS saves `localStorage['theme']` and toggles `.dark`, and the bridge sets `data-theme`. The pressed state starts from the saved value (or the panel default, light) and follows every `theme-changed` event, so it also updates when Filament's own switcher is used.

Filament's user-menu switcher initialises from storage once and does not listen to that event, so it would show a stale choice after the topbar control is used. The panel theme hides it from `md` up (`.fi-dropdown-list:has(> .fi-theme-switcher)`); below `md` the topbar control is hidden and the user-menu switcher is the theme control. `->themeSwitcher()` stays enabled for that reason.

Rules:

- In panel views, use semantic utilities (`bg-surface`, `text-fg`...) or Filament components; never hex values.
- Do not set `data-theme` yourself in a panel view; the bridge owns it.
- If the storage key or values change in `theme.js`, Filament's key (`theme`) no longer matches: update both or add a sync.
- Change the theme only through the `theme-changed` event (the theme control does); never write `localStorage['theme']` or toggle `.dark` directly in a panel view.

### Touch-target exceptions (Filament internals)

The panel theme raises Filament buttons, text inputs and sidebar items to 44px and form text to 16px. These Filament controls keep their built-in size, because an invisible hit area would overflow their scroll containers: 32px icon buttons (column manager, sidebar group toggle, password reveal), table header sort buttons, table row links ("View"), breadcrumbs, the pagination page-size select (14px text; Filament already switches it to 16px on iOS), and checkboxes (their label is part of the target). The viewport check reports them; they are accepted until Filament exposes size options.

The segmented controls (language switcher, theme control) and the app panel's test/live switch are compact (30px options, 36px group) only on `desktop:` (at least 1024px wide with a fine pointer); on touch screens they keep 44px targets. This follows WCAG 2.5.8 (24px minimum) and keeps 2.5.5-level targets wherever touch is possible.
