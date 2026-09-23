# Components

This is the catalogue of Blade components in `resources/views/components/`: purpose, props, slots, a usage example and do/don't for each. It is for developers composing views or changing a component. Accessibility behavior is summarized in [accessibility.md](accessibility.md#aria-conventions-per-component); money and payment usage is in [payments-ui.md](payments-ui.md).

## Quick reference

| Component | Tag | Use for |
|---|---|---|
| Layout | `<x-layouts.app>` | Every full page |
| Button | `<x-button>` | Actions and button-styled links |
| Input | `<x-input>` | Text-like form fields with label, hint, error, affixes |
| Card | `<x-card>` | Bordered surface container |
| Alert | `<x-alert>` | Inline feedback messages |
| Badge | `<x-badge>` | Short status labels |
| Payment status | `<x-payment-status>` | A payment's status (wraps `<x-badge>`) |
| Amount | `<x-amount>` | Any displayed monetary value |
| Icon | `<x-icon>` | Heroicons SVG |
| Theme toggle | `<x-theme-toggle>` | Light / Dark / System switch |
| Language switcher | `<x-language-switcher>` | English / Español switch (no-JS form) |
| Site controls | `<x-site-controls>` | Language switcher + theme toggle for page headers |

## Conventions for all components

- **Props in Blade use kebab-case**: `loading-label`, `icon-trailing`, `hide-label`, `show-labels`. Pass PHP values with `:` (`:value="$total"`, `:signed="false"`).
- **Extra attributes pass through.** Components use `$attributes->class()` / `->merge()`, so your `class`, `id`, `data-*` and ARIA attributes are added to the root element (for `<x-input>`, to the `<input>` itself).
- **Classes are appended, not conflict-merged.** There is no tailwind-merge. Passing `px-2` to a button that already has `px-4` produces both classes, and which one wins is unpredictable. Add **layout** classes only (`w-full`, `mt-*`, `self-end`); change padding, height, font size and colors through **props** (`size`, `variant`, `padding`).
- **Every user-facing string is translated.** Pass `__('...')` to `label`, `title`, `hint`, `error`, `loading-label` and slots. Component defaults (loading text, alert prefixes, theme labels) are already translated. See [i18n.md](i18n.md).
- **Mobile-first.** Every component fits a 320px viewport in both languages: text wraps or truncates, never overflows. See [responsive.md](responsive.md).

### Invalid input policy

One rule for every component, implemented once in `App\Support\ComponentMisuse::report()`:

| Environment | Unknown variant / size / tag / status / icon, non-numeric amount, icon-only button without `label` |
|---|---|
| `local`, `testing` | Throws `InvalidArgumentException`: fix it before it ships |
| Anything else | Logs a warning and renders the fallback below: a typo never breaks a payment page |

| Component | Fallback |
|---|---|
| `<x-button>` | `secondary` variant, `md` size (neutral, never promoted to primary) |
| `<x-badge>` | `neutral` |
| `<x-alert>` | `info` |
| `<x-card>` | `div`, `md` padding |
| `<x-icon>` | `outline` variant, `md` size; unknown name → empty same-size placeholder |
| `<x-amount>` | Em dash "—" + sr-only "Amount unavailable" (never a misleading 0) |
| `<x-payment-status>` | Neutral "Unknown status" badge |

---

## Layout: `<x-layouts.app>`

Base HTML document: meta tags, theme pre-paint script, fonts, Vite assets, skip link and `<main>`. It sets `<html lang>` from the request locale, `<html data-loading-label>` (translated default for `forms.js`), and `viewport-fit=cover` so the safe-area tokens work.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string\|null | `null` | Rendered as `{title} · {app name}`, or the app name alone |

| Slot | Where it renders |
|---|---|
| default | Inside `<main id="main" tabindex="-1">` |
| `head` | End of `<head>` |
| `header` | Before `<main>` |

Extra attributes go to `<main>`.

```blade
<x-layouts.app :title="__('checkout.title')">
    <x-slot:header>
        <header class="sticky top-0 z-sticky border-b border-line bg-page pt-safe-top">
            <div class="mx-auto flex max-w-content flex-wrap items-center justify-between gap-x-4 gap-y-2 px-gutter py-stack-sm">
                ...brand...
                <x-site-controls />
            </div>
        </header>
    </x-slot:header>

    <div class="mx-auto max-w-narrow px-gutter py-section">...</div>
</x-layouts.app>
```

Do: use it for every page. Don't: add a second theme script or change `data-theme` handling here without updating `theme.js` (see [theming.md](theming.md)).

---

## Button: `<x-button>`

Renders `<a>` when `href` is given, otherwise `<button>`.

| Prop | Type | Default | Values |
|---|---|---|---|
| `variant` | string | `primary` | `primary`, `secondary`, `accent`, `ghost`, `danger` (`outline` is an alias of `secondary`) |
| `size` | string | `md` | `sm`, `md`, `lg` |
| `href` | string\|null | `null` | Renders a link |
| `type` | string | `button` | Button `type` (ignored for links) |
| `disabled` | bool | `false` | `disabled` attribute on buttons; on links removes `href` and sets `aria-disabled` |
| `loading` | bool | `false` | Spinner + `aria-disabled` + `aria-busy` + `data-loading`; stays focusable |
| `loadingLabel` | string\|null | `__('ui.button.loading')` ("Processing" / "Procesando") | Screen-reader text while loading; also rendered as `data-loading-label` on `<button>` so `forms.js` announces the same text |
| `icon` | string\|null | `null` | Leading icon; with an empty slot, an icon-only button |
| `iconTrailing` | string\|null | `null` | Trailing icon (hidden while loading) |
| `label` | string\|null | `null` | `aria-label`; **required** for icon-only buttons |

| Variant | Meaning |
|---|---|
| `primary` | The main, transactional action (pay, confirm, save) |
| `secondary` | Alternative action; bordered |
| `accent` | Non-transactional calls to action only (marketing, onboarding) |
| `ghost` | Low-emphasis action, toolbars. Hover `surface-alt`, pressed `surface-pressed` (distinct) |
| `danger` | Destructive actions (refund, cancel, delete). Label `text-on-error` |

| Size | Text button | Icon-only |
|---|---|---|
| `sm` | `min-h-9`, `text-sm` (looks 36px; a transparent `::before` extends the **hit area to 44px**) | `size-9` (hit area 44x44) |
| `md` | `min-h-touch`, `text-base` (44px) | `size-touch` |
| `lg` | `min-h-12`, `text-lg` (48px) | `size-12` |

```blade
<x-button type="submit" icon="lock-closed">{{ __('checkout.pay_now') }}</x-button>
<x-button variant="secondary" href="{{ route('orders.index') }}">{{ __('orders.back') }}</x-button>
<x-button variant="danger" icon="arrow-uturn-left">{{ __('payments.refund') }}</x-button>
<x-button icon="arrow-path" :label="__('payments.retry')" />
<x-button loading :loading-label="__('checkout.processing_payment')">{{ __('checkout.pay_now') }}</x-button>
```

| Do | Don't |
|---|---|
| One `primary` per view or form | Use `accent` for pay or confirm |
| `size="md"` or `lg` for touch-critical actions | Pass `px-*`, `h-*`, `text-*` or color classes |
| Give icon-only buttons a `label` | Use `disabled` for an in-flight submit (use `loading`, which keeps focus) |
| Keep `gap-2` (8px) or more between adjacent `sm` buttons, so hit areas do not overlap | Add `whitespace-nowrap` to a button: long labels must wrap on narrow screens |

Long labels wrap (centered) instead of overflowing; the minimum height is kept. Route names and translation keys in the examples are illustrative.

---

## Input: `<x-input>`

Text-like input with label, hint, error and optional affixes.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | string | required | Always rendered as `<label>` |
| `name` | string\|null | `null` | |
| `id` | string\|null | `name`, else `input-` + random | Used to derive hint/error/affix ids |
| `type` | string | `text` | |
| `value` | mixed | `null` | Omitted when null. The component never reads `old()` or the session; pass it yourself |
| `hint` | string\|null | `null` | Rendered below, `text-fg-secondary`, linked via `aria-describedby` |
| `error` | string\|null | `null` | Red border, error message with icon, `aria-invalid="true"` |
| `hideLabel` | bool | `false` | Label becomes `sr-only` |
| `align` | string | `start` | `start` or `end` (`end` for amounts) |
| `wrapperClass` | string\|null | `null` | Classes for the outer wrapper (`wrapper-class="md:col-span-2"`) |

| Slot | Use |
|---|---|
| `prefix` | Short non-interactive text before the value (e.g. `$`) |
| `suffix` | Short non-interactive text after the value (e.g. `USD`, `%`) |

**Class routing:** `class` goes to the `<input>` (e.g. `class="amount"`); `wrapper-class` goes to the outer wrapper (grid placement, width, margins). Every other attribute (`placeholder`, `inputmode`, `autocomplete`, `required`, `readonly`, `disabled`...) goes to the `<input>`. The control is always 16px (`text-base`), so iOS does not zoom on focus. A caller's `aria-describedby` is kept and merged. States: `disabled` → `bg-surface-alt` + disabled opacity; `readonly` (enabled) → `border-line` + `bg-surface`.

```blade
<x-input name="email" type="email" :label="__('checkout.email')" autocomplete="email" required
         :value="old('email')" :error="$errors->first('email')" />

<x-input name="amount" :label="__('checkout.amount')" inputmode="decimal" align="end" class="amount"
         wrapper-class="md:col-span-2">
    <x-slot:prefix>$</x-slot:prefix>
    <x-slot:suffix>USD</x-slot:suffix>
</x-input>
```

| Do | Don't |
|---|---|
| Always pass a meaningful `label` (use `hide-label` if it must be invisible) | Use `placeholder` as the label |
| Pass `:error="$errors->first('field')"` | Put buttons or links in affix slots |
| Use `class` for input-level utilities like `amount`, `wrapper-class` for layout | Expect `class` to style the wrapper: it lands on the `<input>` |
| Let validation messages come from `lang/<locale>/validation.php` | Pass a smaller `text-*` to the input (breaks the 16px iOS rule) |

---

## Card: `<x-card>`

Bordered `bg-surface` container, `rounded-lg`.

| Prop | Type | Default | Values |
|---|---|---|---|
| `as` | string | `div` | `div`, `section`, `article`, `aside` (anything else: [invalid input policy](#invalid-input-policy), `div`) |
| `padding` | string | `md` | `none`, `sm` (`p-inset-sm`), `md` (`p-inset-md`), `lg` (`p-inset-lg`) |
| `elevated` | bool | `false` | `shadow-md` instead of `shadow-xs` |

| Slot | Notes |
|---|---|
| default | Body, padded |
| `header` | Top section, `font-semibold`, bottom border; accepts its own `class` |
| `footer` | Bottom section, top border; accepts its own `class` |

The same padding applies to header, body and footer.

```blade
<x-card as="article">
    <x-slot:header>{{ __('checkout.summary') }}</x-slot:header>
    ...
    <x-slot:footer class="flex flex-wrap justify-end gap-2">
        <x-button variant="ghost" size="sm">{{ __('ui.cancel') }}</x-button>
        <x-button size="sm">{{ __('ui.confirm') }}</x-button>
    </x-slot:footer>
</x-card>
```

Do: pick a semantic `as` for landmarks. Don't: pass `p-*` classes; use `padding`.

---

## Alert: `<x-alert>`

Inline feedback with a start-side border (`border-s`, flips for RTL), tinted background, icon and a hidden, translated severity prefix (`ui.alert.*`: "Error:" / "Error:", "Warning:" / "Advertencia:"...).

| Prop | Type | Default | Values |
|---|---|---|---|
| `variant` | string | `info` | `success`, `warning`, `error`, `info` |
| `title` | string\|null | `null` | Bold first line; body goes in the slot |
| `focus` | bool | `false` | Adds `tabindex="-1"` + `data-autofocus`; focused on page load |

| Variant | Icon | Default `role` |
|---|---|---|
| `success` | `check-circle` | `status` |
| `warning` | `exclamation-triangle` | `alert` |
| `error` | `x-circle` | `alert` |
| `info` | `information-circle` | `status` |

Pass `role="note"` to override for static, non-urgent content.

```blade
<x-alert variant="error" :title="__('checkout.declined')" focus>{{ __('checkout.try_another_card') }}</x-alert>
<x-alert variant="info" role="note">{{ __('payments.settlement_schedule') }}</x-alert>
```

Do: use `focus` for alerts rendered with the page after a submit. Don't: stack several `role="alert"` messages on load.

---

## Badge: `<x-badge>`

Short pill label.

| Prop | Type | Default | Values |
|---|---|---|---|
| `variant` | string | `neutral` | `neutral`, `success`, `warning`, `error`, `info` |
| `icon` | string\|null | `null` | Heroicons name, rendered from the `micro` set at `xs` |

Never wraps; a label longer than its container truncates with an ellipsis instead of overflowing.

```blade
<x-badge variant="success" icon="check">{{ __('payments.paid') }}</x-badge>
```

Do: keep the text meaningful on its own. Don't: use a badge for a payment status; use `<x-payment-status>`.

---

## Payment status: `<x-payment-status>`

Renders a badge whose label, variant and icon come from `App\Enums\PaymentStatus`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `status` | `PaymentStatus`\|string | required | A string is converted with `PaymentStatus::tryFrom()`; unknown values follow the [invalid input policy](#invalid-input-policy) |

The label is translated (`payments.status.<value>`).

```blade
<x-payment-status status="captured" />
<x-payment-status :status="$payment->status" />
```

The mapping table is in [payments-ui.md](payments-ui.md#payment-status).

---

## Amount: `<x-amount>`

Formatted monetary value with tabular figures and an explicit sign.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `value` | int\|float\|numeric string | required | Non-numeric: [invalid input policy](#invalid-input-policy) (em dash in production) |
| `currency` | string\|null | `Number::defaultCurrency()` | ISO 4217; upper-cased. Belongs to the **transaction** |
| `locale` | string\|null | `app()->getLocale()` | ICU locale. Belongs to the **viewer**: leave it unset |
| `signed` | bool | `true` | Shows `+`/`−` and positive/negative color; zero is unsigned and neutral |
| `minor` | bool | `false` | Value is in minor units; divided by the currency's ICU fraction digits |

```blade
<x-amount :value="1250.5" currency="USD" />
<x-amount :value="129900" currency="CLP" locale="es_CL" minor />
<x-amount :value="$balance" currency="USD" :signed="false" />
```

Display only. Details and rules in [payments-ui.md](payments-ui.md#amounts).

---

## Icon: `<x-icon>`

Server-rendered Heroicons SVG (via blade-icons `svg()`) that inherits `currentColor`.

| Prop | Type | Default | Values |
|---|---|---|---|
| `name` | string | required | Heroicons name (`check-circle`) or a full blade-icons name (`heroicon-s-bolt`) |
| `variant` | string | `outline` | `outline` (o), `solid` (s), `mini` (m), `micro` (c) |
| `size` | string | `md` | `xs`, `sm`, `md`, `lg`, `xl` → `size-icon-*` |
| `label` | string\|null | `null` | Makes the icon meaningful: `role="img"` + `aria-label`. Pass a translated string |

Unknown names, variants or sizes follow the [invalid input policy](#invalid-input-policy) (production: empty placeholder of the same size).

```blade
<x-icon name="credit-card" />
<x-icon name="lock-closed" variant="mini" size="sm" :label="__('checkout.secure')" class="text-success" />
```

Do: color icons with text utilities (`text-success`). Don't: use the per-icon package components (`<x-heroicon-o-bell />`); they are disabled in `config/blade-icons.php`, and the package's own `<x-icon>` is disabled so this atom owns the name.

---

## Theme toggle: `<x-theme-toggle>`

Light / Dark / System radio group, driven by `resources/js/theme.js`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `showLabels` | bool | `false` | Show text labels **from `md` up**; below `md` (and always when false) icons only, with sr-only names and a `title` tooltip |

Labels come from `ui.theme.*`. Each option is 44x44px minimum.

```blade
<x-theme-toggle show-labels />
```

Behavior and storage are documented in [theming.md](theming.md).

---

## Language switcher: `<x-language-switcher>`

One submit button per entry in `config('app.supported_locales')`, inside a CSRF-protected `POST` form to `route('locale.update')`. Works without JavaScript. No props.

| Behavior | Detail |
|---|---|
| Names | Native names ("English", "Español"), each button has `lang="<code>"` so screen readers pronounce it right |
| Below `sm` | Visible text is the code ("EN", "ES"); the native name stays the accessible name |
| Current language | `aria-pressed="true"` |
| Group | `role="group"` named by `ui.language.label` |
| After submit | Cookie `locale` (1 year, SameSite=Lax), redirect to the same page with any `?lang=` removed |
| Target size | 44x44px minimum |

```blade
<x-language-switcher />
```

Resolution order and cookie details are in [i18n.md](i18n.md#how-the-locale-is-chosen).

---

## Site controls: `<x-site-controls>`

Groups `<x-language-switcher>` and `<x-theme-toggle>` for page headers (`role="group"`, "Display settings"). It wraps: put it in a header with `flex-wrap` and the controls drop to their own line at 320px.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `themeLabels` | bool | `false` | Passed to the theme toggle as `show-labels` |

```blade
<header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-stack-lg">
    <span class="min-w-0 truncate font-semibold">{{ config('app.name') }}</span>
    <x-site-controls />
</header>
```
