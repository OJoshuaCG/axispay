{{--
    Amount atom: formats a monetary value with tabular figures and an explicit sign.

    Usage:
        <x-amount :value="1250.5" currency="USD" />                 en: +$1,250.50   es: +1.250,50 US$ (green)
        <x-amount :value="-42" currency="EUR" locale="de_DE" />    −42,00 € (red)
        <x-amount :value="129900" currency="CLP" minor />         value in minor units
        <x-amount :value="99.9" currency="USD" :signed="false" />  plain, neutral color

    Props:
        value     int|float|numeric-string
        currency  ISO 4217 code (default: \Illuminate\Support\Number::defaultCurrency())
        locale    ICU locale (default: the viewer's locale, app()->getLocale(),
                  set per request by App\Http\Middleware\SetLocale)
        signed    show +/− and use positive/negative colors (default true)
        minor     value is in minor units (cents); divided by the currency's
                  fraction digits as reported by ICU (e.g. 2 for USD, 0 for JPY)

    Color is never the only signal: signed amounts always carry "+" or "−"
    (U+2212 MINUS SIGN). Screen readers do not all announce "+", so where the
    direction matters, give it context in text too (e.g. a "Refund" label).

    Currency is per transaction; locale is per viewer. A USD payment viewed in
    Spanish renders "1.234,56 US$" (ICU 77 es: dot grouping, comma decimals,
    symbol after the number); in English "$1,234.56".

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
])

@php
    $currency = strtoupper($currency ?? \Illuminate\Support\Number::defaultCurrency());
    $locale = $locale ?? app()->getLocale();
    $valid = is_numeric($value);

    if (! $valid) {
        \App\Support\ComponentMisuse::report('<x-amount> received a non-numeric value.', [
            'component' => 'amount',
            'type' => get_debug_type($value),
            'currency' => $currency,
        ]);
    }

    $number = $valid ? $value + 0 : 0;

    if ($valid && $minor) {
        $digits = (new \NumberFormatter($locale.'@currency='.$currency, \NumberFormatter::CURRENCY))
            ->getAttribute(\NumberFormatter::FRACTION_DIGITS);
        $number = $number / (10 ** $digits);
    }

    $formatted = \Illuminate\Support\Number::currency(abs($number), in: $currency, locale: $locale);

    $sign = match (true) {
        ! $signed || $number == 0 => '',
        $number > 0 => '+',
        default => "\u{2212}",
    };

    $color = match (true) {
        ! $signed || $number == 0 => '',
        $number > 0 => 'text-amount-positive',
        default => 'text-amount-negative',
    };
@endphp

@if ($valid)
    <span {{ $attributes->class(['amount whitespace-nowrap', $color]) }}>{{ $sign }}{{ $formatted }}</span>
@else
    <span {{ $attributes->class('amount whitespace-nowrap text-fg-secondary') }}><span aria-hidden="true">&mdash;</span><span class="sr-only">{{ __('ui.amount.unavailable') }}</span></span>
@endif
