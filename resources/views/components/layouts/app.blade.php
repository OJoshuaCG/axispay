{{--
    Base application layout.

    Usage:
        <x-layouts.app title="Checkout">
            ...
        </x-layouts.app>

    Named slots: head (extra <head> content), header (rendered above <main>).

    i18n: <html lang> follows the request locale (App\Http\Middleware\SetLocale).
    <html data-loading-label> carries the translated default loading text for
    resources/js/forms.js (JS never hardcodes user-facing strings).

    viewport-fit=cover lets the page draw under notches; the gutter token and
    sticky headers add env(safe-area-inset-*) so content never hides there.
--}}
@props([
    'title' => null,
])

@php
    $appName = \App\Modules\Shared\Support\Brand::displayName();
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-loading-label="{{ __('ui.button.loading') }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        {{--
            Browser UI color = page background (--color-page: neutral-0 / neutral-900).
            These two values are the only copy of those hexes outside the tokens:
            they are needed before any CSS loads. A manual theme overrides them:
            the pre-paint script below copies the chosen scheme's value into both
            tags, and resources/js/theme.js then writes the computed --color-page.
        --}}
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)" data-theme-color="light">
        <meta name="theme-color" content="#0d1017" media="(prefers-color-scheme: dark)" data-theme-color="dark">

        <title>{{ filled($title) ? $title.' · '.$appName : $appName }}</title>

        {{--
            Apply the saved theme before first paint to avoid a flash. With no
            saved (or an unreadable) preference the page is light (ADR-0044);
            only an explicit "system" follows the OS. Keep in sync with
            resources/js/theme.js (STORAGE_KEY, THEMES, DEFAULT_THEME).
        --}}
        <script @if ($cspNonce) nonce="{{ $cspNonce }}" @endif>
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
                try {
                    var source = document.querySelector('meta[data-theme-color="' + theme + '"]');
                    var color = source.getAttribute('content');
                    document.querySelectorAll('meta[data-theme-color]').forEach(function (meta) {
                        meta.setAttribute('data-default-content', meta.getAttribute('content'));
                        meta.setAttribute('content', color);
                    });
                } catch (error) {}
            })();
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{ $head ?? '' }}
    </head>
    <body class="min-h-dvh bg-page font-sans text-fg">
        <a
            href="#main"
            class="sr-only rounded-md bg-primary px-4 py-3 font-medium text-on-primary no-underline focus:not-sr-only focus:fixed focus:top-edge focus:start-edge focus:z-tooltip"
        >
            {{ __('ui.layout.skip_to_content') }}
        </a>

        {{ $header ?? '' }}

        <main id="main" tabindex="-1" {{ $attributes->class('focus-visible:outline-none') }}>
            {{ $slot }}
        </main>
    </body>
</html>
