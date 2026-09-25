# Frontend design system

This is the entry point to the AxisPay frontend design system (Laravel 13 Blade components + Tailwind CSS v4). The UI ships in English and Spanish, light and dark, and must work from 320px phones to desktop. It is for anyone, human or agent, who writes CSS, Blade components or views. Read the golden rules below before touching UI code; the linked pages hold the detail.

## Quick path

1. Start the asset server and the app:

   ```sh
   npm run dev
   php artisan serve
   ```

2. Open `http://127.0.0.1:8000/design-system`. The route exists only when `APP_ENV=local` (see `routes/web.php`).
3. Switch Light / Dark / System and English / Español with the controls in the page header (or add `?lang=es`), and check your change in both themes, both languages, at 320px and desktop.

The preview page is `resources/views/design-system.blade.php`. It writes every class literally so Tailwind's scanner finds it; keep it that way when you add examples.

## Golden rules

1. **Use design-system utilities only.** Never write hex values, `rgb()`, or arbitrary values such as `bg-[#1f63c7]` or `p-[13px]` in views. Tailwind's default palette, type scale, radii, shadows and easings are removed, so `bg-white`, `text-gray-500` or `shadow-2xl` do not exist. See [tokens.md](tokens.md).
2. **Body text is `text-fg`. `text-primary` is brand blue**, for emphasis and brand accents, not for paragraphs. Secondary copy uses `text-fg-secondary`; `text-fg-muted` is for placeholders and non-essential metadata and is never used on `bg-surface-alt`.
3. **Change a value in one place.** Each kind of value has exactly one home file (table below). Components read tokens; they never redefine them.
4. **Primary is for action; feature is decorative.** `primary` (buttons, focus, selection) marks the main transactional action. `feature` / `feature-subtle` is brand decoration only, never buttons, links or text that must be read.
5. **Accent green is for non-transactional calls to action** (marketing, onboarding). Never use `variant="accent"` for pay or confirm buttons.
6. **Money always goes through `<x-amount>`** (or at minimum the `amount` utility). Signed amounts always carry `+` or `−`; color is never the only signal. See [payments-ui.md](payments-ui.md).
7. **Currency and numeric money data always use `font-numeric` (Geist Mono)**, through `<x-amount>` or the `amount` utility (amount inputs: `class="amount"`; Filament columns: `->fontFamily(FontFamily::Mono)`). Text is Mukta (`font-sans`). Never set a font family by hand on money. See [payments-ui.md](payments-ui.md#numeric-font) and ADR-0042.
8. **Payment states come from `App\Enums\PaymentStatus`** via `<x-payment-status>`. Never hand-pick a badge color or label for a status.
9. **Every user-facing string goes through `__()`**, with the key in both `lang/en` and `lang/es` (including `label`, sr-only text and JS strings passed via `data-*`). Never concatenate translated fragments. See [i18n.md](i18n.md).
10. **Design mobile-first and verify at 320px** in Spanish (the longest strings): no horizontal scroll, no clipped text, 44px targets, 16px inputs. See [responsive.md](responsive.md).
11. **Check both themes.** Semantic tokens swap automatically; if something only looks right in one theme, the token choice is wrong. See [theming.md](theming.md).
12. **Invalid component input throws locally and degrades in production** (one policy, `App\Support\ComponentMisuse`). See [components.md](components.md#invalid-input-policy).

## Where do I change X?

| I want to change... | File |
|---|---|
| A raw color value (a hex in a scale) | `resources/css/tokens/primitives.css` |
| Which color a role uses in light or dark (e.g. what `primary` maps to) | `resources/css/tokens/semantic.css` |
| Shadows (`shadow-xs` ... `shadow-xl`) | `resources/css/tokens/semantic.css` (they use `light-dark()`) |
| Spacing, layout widths, type scale, font families, weights, tracking, radius, icon sizes, motion, z-index, disabled opacity | `resources/css/theme.css` |
| The `dark:` and `desktop:` variant definitions | `resources/css/theme.css` |
| Global element defaults (body, links, focus outline, selection, reduced motion) | `resources/css/base.css` |
| Custom utilities Tailwind cannot express (`amount`: numeric font + tabular figures; `seg-group` / `seg-option`: segmented controls) | `resources/css/components.css` |
| Panel type scale (Filament text sizes for Mukta) | `resources/css/theme.css` (`--panel-text-*`), applied in `resources/css/filament/theme.css` |
| Panel theme control, default panel theme | `resources/views/filament/partials/theme-control.blade.php`, `app/Support/Filament/PanelDefaults.php` |
| Import order of the CSS layers | `resources/css/app.css` |
| Font loading (Mukta and Geist Mono weights, subsets, preload, fontaine fallback metrics) | `vite.config.js` |
| Breakpoint contract, safe-area and gutter tokens | `resources/css/theme.css` |
| A user-facing string (English / Spanish) | `lang/en/<domain>.php` and `lang/es/<domain>.php` |
| Supported languages | `config/app.php` → `supported_locales` |
| How the locale is picked (query, cookie, header) | `app/Http/Middleware/SetLocale.php`, `app/Support/Locales.php` |
| Language switch endpoint | `app/Http/Controllers/LocaleController.php` (`POST /locale`) |
| Invalid-input policy for components | `app/Support/ComponentMisuse.php` |
| A component's markup, variants or props | `resources/views/components/<name>.blade.php` |
| Payment status badge variant or icon | `app/Enums/PaymentStatus.php` (labels: `lang/*/payments.php`) |
| Theme persistence, toggle keyboard behavior, browser `theme-color` | `resources/js/theme.js` (keep the pre-paint script in `components/layouts/app.blade.php` in sync) |
| Double-submit guard, loading buttons | `resources/js/forms.js` |
| Autofocus of feedback on page load | `resources/js/focus.js` |
| Icon package registration | `config/blade-icons.php` |

## Documentation map

| Page | Read it when you need to... |
|---|---|
| [tokens.md](tokens.md) | Find a token, understand the architecture, or add a new token |
| [theming.md](theming.md) | Work on light/dark behavior, the theme toggle, or `dark:` |
| [accessibility.md](accessibility.md) | Check contrast, focus, touch targets, ARIA, and run the pre-merge checklist |
| [components.md](components.md) | Use or change a Blade component (props, slots, examples) |
| [payments-ui.md](payments-ui.md) | Build amounts, payment forms, statuses and checkout feedback |
| [i18n.md](i18n.md) | Add or translate a string, add a language, format money and dates per locale |
| [responsive.md](responsive.md) | Follow the breakpoint contract, fix overflow, run the viewport check |

## CSS entry point

`resources/css/app.css` imports, in this order: `tailwindcss` → `tokens/primitives.css` → `tokens/semantic.css` → `theme.css` → `base.css` → `components.css`. The order matters: semantic tokens reference primitives, and the theme file maps both to utilities.
