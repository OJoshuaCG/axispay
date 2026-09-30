{{--
    Amount atom: formats a monetary value with tabular figures, an explicit
    sign and the ISO currency code (ADR-0049).

    Usage:
        <x-amount :value="1250.5" currency="USD" />                 +1,250.50 USD (green), in en and es
        <x-amount :value="-42" currency="EUR" locale="de_DE" />    −42,00 EUR (red)
        <x-amount :value="129900" currency="CLP" minor />         value in minor units: +129,900 CLP
        <x-amount :value="99.9" currency="USD" :signed="false" />  plain, neutral color: 99.90 USD

    Props:
        value     int|float|numeric-string
        currency  ISO 4217 code (default: \Illuminate\Support\Number::defaultCurrency())
        locale    interface or ICU locale (default: the viewer's formatting
                  locale: es → es_MX, en → en_US, see App\Support\Locales)
        signed    show +/− and use positive/negative colors (default true)
        minor     value is in minor units (cents); the currency's decimals
                  come from ISO 4217 (2 for USD and MXN, 0 for CLP)
        split     render the number and the code as two pieces that may wrap
                  onto two lines (the number itself never breaks), for large
                  totals in narrow columns: "1,500,000.00 MXN" at text-3xl is
                  wider than a 320px phone's card (ADR-0056 part C). The
                  string is split at its last non-breaking space; the text
                  read is the same.
        codeClass classes of the currency code when `split` (e.g. a smaller
                  size and fg-secondary); the weight is inherited, never set

    Formatting is App\Modules\Shared\Money\MoneyDisplay, the same one the
    panels use: number with the currency's decimals, a non-breaking space and
    the code. Never a bare "$", which is ambiguous between USD and MXN.

    Color is never the only signal: signed amounts always carry "+" or "−"
    (U+2212 MINUS SIGN). Screen readers do not all announce "+", so where the
    direction matters, give it context in text too (e.g. a "Refund" label).

    Invalid value (non-numeric): ComponentMisuse policy. Throws in local/testing;
    elsewhere logs a warning and renders an em dash "—" with screen-reader text
    (ui.amount.unavailable), never a misleading 0.

    Display only. Never use the formatted string for arithmetic or storage.
--}}
@props([
    'value',
    'currency' => null,
    'locale' => null,
    'signed' => true,
    'minor' => false,
    'split' => false,
    'codeClass' => '',
])

@php
    $currency = strtoupper($currency ?? \Illuminate\Support\Number::defaultCurrency());
    $valid = is_numeric($value);

    if (! $valid) {
        \App\Support\ComponentMisuse::report('<x-amount> received a non-numeric value.', [
            'component' => 'amount',
            'type' => get_debug_type($value),
            'currency' => $currency,
        ]);
    }

    $decimal = $valid ? \Brick\Math\BigDecimal::of(is_float($value) ? var_export($value, true) : (string) $value) : null;
    $formatted = $valid ? \App\Modules\Shared\Money\MoneyDisplay::amount($value, $currency, minor: (bool) $minor, locale: $locale) : '';

    $sign = match (true) {
        ! $valid || ! $signed || $decimal->isZero() => '',
        $decimal->isPositive() => '+',
        default => "\u{2212}",
    };

    // '' when neutral: filtered out of the class list below, so no stray
    // double space is left before the caller's classes.
    $color = match (true) {
        ! $valid || ! $signed || $decimal->isZero() => '',
        $decimal->isPositive() => 'text-amount-positive',
        default => 'text-amount-negative',
    };
@endphp

@php
    $separatorAt = $valid && $split ? mb_strrpos($formatted, \App\Modules\Shared\Money\MoneyDisplay::SEPARATOR) : false;
@endphp

@if ($separatorAt !== false)
    <span {{ $attributes->class(array_filter(['amount inline-flex flex-wrap items-baseline gap-x-2', $color]))->merge(['lang' => \App\Support\Locales::formattingLanguageTag($locale)]) }}><span class="whitespace-nowrap">{{ $sign }}{{ mb_substr($formatted, 0, $separatorAt) }}</span> <span @class([$codeClass])>{{ mb_substr($formatted, $separatorAt + 1) }}</span></span>
@elseif ($valid)
    <span {{ $attributes->class(array_filter(['amount whitespace-nowrap', $color]))->merge(['lang' => \App\Support\Locales::formattingLanguageTag($locale)]) }}>{{ $sign }}{{ $formatted }}</span>
@else
    <span {{ $attributes->class('amount whitespace-nowrap text-fg-secondary') }}><span aria-hidden="true">&mdash;</span><span class="sr-only">{{ __('ui.amount.unavailable') }}</span></span>
@endif
