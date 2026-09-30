{{--
    Theme pre-paint script: applies the saved theme before first paint, so
    the page never flashes the wrong theme. Included right after
    <x-theme-color-meta /> by every Blade layout (the app layout and the
    checkout layout, ADR-0044, ADR-0056 part C).

    With no saved (or an unreadable) preference the page is light (ADR-0044);
    only an explicit "system" follows the OS. For a manual theme it also:
        - copies the chosen scheme's theme-color into both metas (keeping the
          server value in data-default-content for resources/js/theme.js);
        - narrows <meta name="color-scheme"> to that scheme, so the browser's
          own UI (scrollbars, form controls) matches before the CSS loads.

    Keep in sync with resources/js/theme.js (STORAGE_KEY, THEMES,
    DEFAULT_THEME). Carries the Vite CSP nonce when one is set (the checkout's
    CSP allows only nonce'd inline scripts).
--}}
@php
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
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
            var scheme = document.querySelector('meta[name="color-scheme"]');
            if (scheme) {
                scheme.setAttribute('content', theme);
            }
            var source = document.querySelector('meta[data-theme-color="' + theme + '"]');
            var color = source.getAttribute('content');
            document.querySelectorAll('meta[data-theme-color]').forEach(function (meta) {
                meta.setAttribute('data-default-content', meta.getAttribute('content'));
                meta.setAttribute('content', color);
            });
        } catch (error) {}
    })();
</script>
