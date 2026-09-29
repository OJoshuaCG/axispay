{{--
    Payer-facing checkout layout (plan 11, docs/frontend/checkout-design.md, ADR-0051).

    Differences from <x-layouts.app>:
        - no theme pre-paint script and no data-theme: the page follows the
          OS (prefers-color-scheme); the payer never gets a theme toggle;
        - robots noindex (also sent as X-Robots-Tag), CSRF meta for the
          page script, preconnect to Stripe's API, Geist Mono 600 preloaded (the
          total);
        - Stripe.js is loaded from js.stripe.com (never bundled, PCI) only
          when the page takes payments; in the sandbox the local stub
          replaces it (resources/js/checkout/sandbox-stripe.js);
        - every script and style carries the CSP nonce (CheckoutSecurityHeaders).

    Props:
        title          page title (rendered as-is: "{state} · {merchant}")
        loadStripe     bool: include Stripe.js / the sandbox stub
        sandbox        bool: sandbox mode (stub instead of Stripe.js)
        numericFont    Geist Mono 600 URL to preload (CheckoutFonts)

    Slots: default (inside <main>), footer (after <main>).
--}}
@props([
    'title',
    'loadStripe' => false,
    'sandbox' => false,
    'numericFont' => null,
])

@php
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $entries = $sandbox && $loadStripe
        ? ['resources/css/app.css', 'resources/js/checkout/sandbox-stripe.js', 'resources/js/checkout/checkout.js']
        : ['resources/css/app.css', 'resources/js/checkout/checkout.js'];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-loading-label="{{ __('ui.button.loading') }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- Page background per scheme (--color-page: neutral-0 / neutral-900), before CSS loads. --}}
        <x-theme-color-meta />

        <x-favicon-links />
        <title>{{ $title }}</title>

        @if ($loadStripe && ! $sandbox)
            {{-- Stripe.js itself is fetched right away below; its first API calls go to api.stripe.com. --}}
            <link rel="preconnect" href="https://api.stripe.com" crossorigin>
        @endif

        @fonts
        @if ($numericFont)
            <link rel="preload" as="font" type="font/woff2" href="{{ $numericFont }}" crossorigin @if ($cspNonce) nonce="{{ $cspNonce }}" @endif>
        @endif

        @if ($loadStripe && ! $sandbox)
            {{-- Stripe.js, versioned like the pinned API version (dahlia); always from js.stripe.com.
                 Deferred: it runs after parsing, before the page's module script (document order). --}}
            <script src="https://js.stripe.com/dahlia/stripe.js" defer @if ($cspNonce) nonce="{{ $cspNonce }}" @endif></script>
        @endif

        @vite($entries)
    </head>
    <body class="min-h-dvh bg-page font-sans text-fg">
        <a
            href="#main"
            class="sr-only rounded-md bg-primary px-4 py-3 font-medium text-on-primary no-underline focus:not-sr-only focus:fixed focus:top-edge focus:start-edge focus:z-tooltip"
        >
            {{ __('ui.layout.skip_to_content') }}
        </a>

        <div class="flex min-h-dvh flex-col px-gutter pt-safe-top pb-safe-bottom">
            <main id="main" tabindex="-1" {{ $attributes->class('flex-1 py-stack-lg focus-visible:outline-none md:py-stack-xl') }}>
                {{ $slot }}
            </main>

            {{ $footer ?? '' }}
        </div>
    </body>
</html>
