{{--
    Card of the payment page (ADR-0056 part C): a `page` surface on the
    checkout's `canvas` body. Light: a white card with a hairline border and
    a small shadow on the near-white canvas. Dark: canvas and page are the
    same value, so the card is flat and the border does the work.

    Not <x-card>: that one is `bg-surface`/raised, and the Pay button's
    primary fill on raised in dark is 2.94:1 (fails WCAG 1.4.11); on `page`
    it is 3.32:1. The Stripe Appearance probe reads `page` for
    colorBackground, so the card form's iframe matches this surface.

    Usage:
        <x-checkout.card>...</x-checkout.card>                    <section>
        <x-checkout.card as="div" class="lg:col-span-3">...</x-checkout.card>

    Props: as (section | div | article | aside; default section)
    Invalid `as`: ComponentMisuse policy (falls back to section).
--}}
@props(['as' => 'section'])

@php
    if (! in_array($as, ['section', 'div', 'article', 'aside'], true)) {
        \App\Support\ComponentMisuse::report("Unknown <x-checkout.card> tag \"{$as}\".", ['component' => 'checkout.card', 'as' => $as]);
        $as = 'section';
    }
@endphp

<{{ $as }} {{ $attributes->class('min-w-0 rounded-xl border border-line bg-page p-inset-md shadow-sm sm:p-inset-lg') }}>{{ $slot }}</{{ $as }}>
