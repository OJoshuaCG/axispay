# Payments UI conventions

This page covers how money and payment flows are presented: amounts, amount and card inputs, confirmation screens, payment statuses, submit protection and post-submit feedback. It is for developers building checkout, dashboards or any screen that shows money. Component props are in [components.md](components.md); ARIA details in [accessibility.md](accessibility.md).

## Quick rules

1. Display every amount with `<x-amount>`. Never format money by hand in a view. Money is always set in Geist Mono (`font-numeric`), through `<x-amount>` or the `amount` utility; never pick its font family by hand.
2. Signed amounts always show `+` or `−`; color is never the only signal.
3. Amount inputs: `inputmode="decimal"`, `align="end"`, `class="amount"`, currency in an affix.
4. Payment statuses come only from `App\Enums\PaymentStatus` via `<x-payment-status>`.
5. Payment forms use `data-prevent-double-submit`, **and** the server enforces idempotency.
6. The Pay button is `variant="primary"`, never `accent`.
7. Feedback after a full-page POST uses `<x-alert ... focus>`.
8. **Currency is per transaction, locale is per viewer.** Pass `currency` from the payment; leave `locale` unset so the amount follows the viewer's language.

## Amounts

### Numeric font

Text is Mukta (`font-sans`); currency amounts and numeric money data are Geist Mono (`font-numeric`, token `--font-numeric`, ADR-0042). The `amount` utility (`resources/css/components.css`) sets both the face and `font-variant-numeric: tabular-nums slashed-zero`, so digits have equal width, columns align and values do not shift while updating.

- Geist Mono is monospace: every digit is 600 units wide, so figures align without any OpenType feature. `tabular-nums` is kept for the fallback faces, which are not all monospace.
- Geist Mono has **no** `zero` feature: slashed zero only appears in fallback fonts that support it. Do not rely on it to distinguish 0 from O.
- Loaded weights: 400 (every amount) and 600 (emphasized totals, `font-semibold`). `font-medium` on an amount renders 400, `font-bold` renders 600.
- `<x-amount>` applies `amount` automatically. Add `class="amount"` yourself on amount inputs (there is no separate `numeric` prop) and on any other numeric money column.
- Geist Mono is wider than Mukta: a 9-character amount at `text-base` is about 86px. Keep amounts `whitespace-nowrap` (as `<x-amount>` does) in a `minmax(0,1fr)_auto` grid so the label wraps, not the amount.
- IDs, keys and code use `font-mono`. It is the same face today, but a separate token: do not use `font-mono` for money or `font-numeric` for code.

### Filament panels

In the panels, `--font-mono` resolves to the numeric stack (`resources/css/filament/theme.css`), so Filament's native API is the convention for money and numeric columns:

```php
use Filament\Support\Enums\FontFamily;

TextColumn::make('amount')->money(...)->fontFamily(FontFamily::Mono)->alignEnd();
TextEntry::make('amount')->money(...)->fontFamily(FontFamily::Mono);
```

`fontFamily(FontFamily::Mono)` adds `fi-font-mono`, compiled to `font-family: var(--font-numeric)`. For other components (stats, custom Blade in a panel), render `<x-amount>` or add the `font-numeric` utility class; the panel theme only generates classes found in its `@source` paths.

### `<x-amount>` behavior

| Input | Output | Color |
|---|---|---|
| `:value="1250.5" currency="USD"` (viewer `en`) | `+$1,250.50` | `text-amount-positive` (= success) |
| `:value="1250.5" currency="USD"` (viewer `es`) | `+1.250,50 US$` | `text-amount-positive` |
| `:value="-42" currency="EUR" locale="de_DE"` | `−42,00 €` | `text-amount-negative` (= error) |
| `:value="0"` | no sign | neutral |
| `:signed="false"` | no sign | neutral |
| `:value="129900" currency="CLP" minor` | value divided by CLP's ICU fraction digits | as signed |
| `:value="abc"` (non-numeric) | throws in `local`/`testing`; elsewhere `—` + sr-only "Amount unavailable", warning logged | neutral |

- The minus is U+2212 (`−`), not a hyphen.
- The formatted value never wraps (`whitespace-nowrap`).
- `currency` defaults to `Number::defaultCurrency()`: always pass the transaction's currency.
- `locale` defaults to `app()->getLocale()`, which `SetLocale` sets per request (and `Number::useLocale()` with it). Force `locale` only for a demo or a document that must render in a fixed locale (e.g. an invoice in the merchant's language).
- ICU decides symbol position, grouping and decimals per locale (ICU 77: `en` `$1,234.56`, `es` `1.234,56 US$`). Do not hand-build these rules.
- Dates: use Carbon's locale-aware formats (`$date->isoFormat('LL')`, `translatedFormat('j F Y')`); Carbon follows the app locale. Render machine values in `<time datetime="...">`.
- `minor` uses ICU's fraction digits for the currency (e.g. 2 for USD, 0 for JPY), so store and pass minor units consistently.
- Screen readers may not announce `+`. Where direction matters, add text context ("Refund", "Charge").
- **Display only.** Never parse, compute with or store the formatted string.

```blade
<dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-2">
    <dt class="break-words text-fg-secondary">{{ __('payments.payment') }}</dt>
    <dd class="text-end"><x-amount :value="$payment->amount" :currency="$payment->currency" /></dd>
    <dt class="break-words text-fg-secondary">{{ __('payments.refund') }}</dt>
    <dd class="text-end"><x-amount :value="-$refund->amount" :currency="$refund->currency" /></dd>
</dl>
```

`minmax(0,1fr)` lets long (Spanish) labels wrap next to a never-wrapping amount at 320px. Model and key names above are illustrative.

```blade
```

Use `:signed="false"` for balances and totals where a sign would be noise.

## Amount and card inputs

```blade
<x-input name="amount" :label="__('checkout.amount')" inputmode="decimal" autocomplete="off"
         align="end" class="amount" placeholder="0.00" :hint="__('checkout.amount_hint')">
    <x-slot:prefix>$</x-slot:prefix>
    <x-slot:suffix>USD</x-slot:suffix>
</x-input>

<x-input name="card_number" :label="__('checkout.card_number')" inputmode="numeric" autocomplete="cc-number" />
```

The decimal separator users type is locale-sensitive (`es` users type `12,50`): normalize input on the server before converting to minor units.

| Field | `inputmode` | `autocomplete` | Other |
|---|---|---|---|
| Amount | `decimal` | `off` | `align="end"`, `class="amount"`, currency symbol in `prefix`, code in `suffix` |
| Card number | `numeric` | `cc-number` | Keep `type="text"` (numeric inputs drop leading zeros and show spinners) |
| Email | — | `email` | `type="email"` |

Affixes are announced after the label (their ids come first in `aria-describedby`), so "USD" is read with the field. Affixes must be short, non-interactive text.

## Confirmation (read-only) state

For a review-before-pay step, render values as read-only inputs:

```blade
<x-input name="fee" :label="__('checkout.fee')" value="2.90" readonly align="end" class="amount">
    <x-slot:suffix>%</x-slot:suffix>
</x-input>
```

Enabled `readonly` inputs switch to `border-line` + `bg-surface`, so they read as fixed values but remain focusable, selectable and submitted with the form. Use `disabled` only when the value is truly unavailable (it is not submitted and drops to disabled opacity).

## Payment status

`app/Enums/PaymentStatus.php` is the single lookup. `<x-payment-status>` reads `label()`, `badgeVariant()` and `icon()` from it. `label()` returns `__('payments.status.<value>')`, so labels live in `lang/{en,es}/payments.php`.

| Case | Value | Label (en / es) | Badge variant | Icon (micro) |
|---|---|---|---|---|
| `Authorized` | `authorized` | Authorized / Autorizado | info | `shield-check` |
| `Captured` | `captured` | Captured / Capturado | success | `check-circle` |
| `Pending` | `pending` | Pending / Pendiente | warning | `clock` |
| `Refunded` | `refunded` | Refunded / Reembolsado | info | `arrow-uturn-left` |
| `PartiallyRefunded` | `partially_refunded` | Partially refunded / Reembolsado parcialmente | info | `arrow-uturn-left` |
| `Disputed` | `disputed` | Disputed / En disputa | warning | `exclamation-triangle` |
| `Failed` | `failed` | Failed / Fallido | error | `x-circle` |
| `Canceled` | `canceled` | Canceled / Cancelado | neutral | `no-symbol` |
| `Expired` | `expired` | Expired / Vencido | neutral | `calendar` |

- To change how a status looks everywhere, edit the enum (variant, icon) or `payments.php` (label), not the view.
- Adding a case: add the case, its `badgeVariant()`/`icon()` arms, and a `payments.status.<value>` key in **every** `lang/<locale>/payments.php`.
- The enum is presentation-only for now: allowed transitions and persistence are not modelled.
- An unknown string passed to `<x-payment-status>` follows the [invalid input policy](components.md#invalid-input-policy): throws in `local`/`testing`, otherwise a neutral "Unknown status" badge plus a logged warning.

## Double-submit guard

Add `data-prevent-double-submit` to payment forms:

```blade
<form method="post" action="..." data-prevent-double-submit>
    @csrf
    ...
    <x-button type="submit" icon="lock-closed" :loading-label="__('checkout.processing_payment')">{{ __('checkout.pay_now') }}</x-button>
</form>
```

What `resources/js/forms.js` does:

| Step | Behavior |
|---|---|
| First submit | Form gets `data-submitting`; the submitter button switches to the loading state (spinner, `aria-disabled`, `aria-busy`, sr-only status) |
| Later submits | Ignored |
| Submit cancelled by your own validation (`preventDefault`) | Guard does not lock the form |
| Page restored from back/forward cache | Lock and client loading state are cleared |
| Any click / Enter / Space inside `[aria-disabled="true"]` | Blocked |

The client-side loading text comes from the button's `data-loading-label`, which `<x-button>` always renders from its (translated) `loading-label` prop. A plain `<button>` falls back to `<html data-loading-label>` (`ui.button.loading`). `forms.js` contains no user-facing text.

> **This is a UX aid, not a payment safeguard.** It needs JavaScript and can be bypassed. Payment endpoints must be idempotent on the server (idempotency keys).

## Loading Pay button

Server-rendered loading state (e.g. while a page waits on an async result):

```blade
<x-button type="submit" loading :loading-label="__('checkout.processing_payment')">{{ __('checkout.pay_now') }}</x-button>
```

The button stays focusable (no `disabled` attribute), so keyboard and screen-reader users do not lose their place mid-submit. `aria-disabled` + `forms.js` prevent activation.

## Feedback after a full-page POST

Alerts rendered with the initial page are not reliably announced as live regions. Pass `focus` so `resources/js/focus.js` moves focus to the alert on load (only if nothing else has focus):

```blade
@if (session('payment_error'))
    <x-alert variant="error" :title="__('checkout.declined')" focus>{{ session('payment_error') }}</x-alert>
@endif
```

The session key above is illustrative; store a translated message (or a key) in it, never raw English. Use one focused alert per page; keep field-level errors on the inputs via `error`.

## Motion and color on the payment path

| Topic | Rule |
|---|---|
| Easing | Use `ease-standard` or `ease-emphasized` (no overshoot). No bouncy or playful motion in checkout. |
| Duration | Prefer `duration-fast` for state changes; reduced motion is handled globally. |
| Accent green | Only for non-transactional CTAs. Pay, confirm, capture use `primary`; refund, cancel use `danger`. |
| Feature color | Decorative only; never on amounts, statuses or buttons. |
| Success green on amounts | Comes from `amount-positive`; do not color amounts manually. |

## Checklist

- [ ] All amounts use `<x-amount>`; numeric columns use `amount` and `text-end` (Filament: `->fontFamily(FontFamily::Mono)`), so money is always Geist Mono.
- [ ] `currency` comes from the transaction; `locale` is left to the viewer (forced only for fixed-locale documents).
- [ ] Checked in English and Spanish: amounts, dates and status labels follow the language.
- [ ] Amount inputs have `inputmode="decimal"`, `align="end"`, `class="amount"` and a currency affix.
- [ ] Statuses use `<x-payment-status>`; no hand-picked badge colors.
- [ ] Payment forms have `data-prevent-double-submit` and a server-side idempotency key.
- [ ] The Pay button is `primary`, `size="md"` or larger.
- [ ] Post-submit error alerts use `focus`.
