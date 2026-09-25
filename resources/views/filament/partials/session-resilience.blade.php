{{--
    Session resilience of the Filament panels (ADR-0040).

    1. A Livewire request answered with 419 (the session expired or was
       invalidated) reloads the page instead of showing Livewire's "This page
       has expired" confirm. The reload gets a fresh CSRF token, or the sign-in
       page when the session is gone. At most one automatic reload per minute
       per tab (sessionStorage timestamp): a second 419 inside that window
       falls back to Livewire's own prompt, so a server-side fault can never
       cause a reload loop.
    2. Keep-alive: every 5 minutes, if the tab is visible AND the person has
       interacted with the page since the previous ping, a GET to the panel's
       /session/ping renews the session. An unattended tab sends nothing, so
       the 2-hour inactivity limit of plan 17.3 still applies to it.

    No user-visible text: the fallback is Livewire's own prompt.
--}}
@php
    $panelId = \Filament\Facades\Filament::getCurrentPanel()?->getId();
    $pingRoute = "{$panelId}.session.ping";
    $pingUrl = \Illuminate\Support\Facades\Route::has($pingRoute) ? route($pingRoute, absolute: false) : null;
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<script @if ($cspNonce) nonce="{{ $cspNonce }}" @endif>
    (function () {
        if (window.__axispaySessionResilience) {
            return;
        }
        window.__axispaySessionResilience = true;

        var RELOAD_KEY = 'axispay:session-expired-reload';
        var RELOAD_WINDOW_MS = 60 * 1000;
        var PING_URL = @json($pingUrl);
        var PING_INTERVAL_MS = 5 * 60 * 1000;

        var lastReload = function () {
            try {
                return Number(window.sessionStorage.getItem(RELOAD_KEY)) || 0;
            } catch (e) {
                return 0;
            }
        };
        var rememberReload = function (at) {
            try {
                window.sessionStorage.setItem(RELOAD_KEY, String(at));
                return true;
            } catch (e) {
                // Without storage there is no loop guard: keep Livewire's prompt.
                return false;
            }
        };

        document.addEventListener('livewire:init', function () {
            window.Livewire.interceptRequest(function (hooks) {
                hooks.onError(function (error) {
                    if (!error.response || error.response.status !== 419) {
                        return;
                    }
                    var now = Date.now();
                    if (now - lastReload() < RELOAD_WINDOW_MS || !rememberReload(now)) {
                        return;
                    }
                    error.preventDefault();
                    window.location.reload();
                });
            });
        });

        if (!PING_URL || typeof window.fetch !== 'function') {
            return;
        }

        var interacted = false;
        var markInteraction = function () {
            interacted = true;
        };
        ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (type) {
            window.addEventListener(type, markInteraction, { passive: true, capture: true });
        });

        window.setInterval(function () {
            if (document.visibilityState !== 'visible' || !interacted) {
                return;
            }
            interacted = false;
            window.fetch(PING_URL, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                // Marks it as AJAX, so the session does not record the ping
                // as the "previous URL" used by redirects back.
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).catch(function () {});
        }, PING_INTERVAL_MS);
    })();
</script>
