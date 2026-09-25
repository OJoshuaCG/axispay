# ADR-0040: Panel session resilience (419 auto-reload and keep-alive)

- **Status:** Proposed (pending the project owner's review)
- **Date:** 2026-09-25
- **Source:** master plan section 17.3 (2-hour inactivity limit); [ADR-0034](0034-phase-1-security-hardening.md) (per-host session cookies)

## Context

On a Dokploy deployment, the panels answered `419 Page Expired` on the second Livewire request of a page: a second sign-in attempt, the 2FA set-up, any action after the first one. The cause was a bug, fixed separately: the shared panel middleware was registered as Livewire *persistent* middleware, so every Livewire update re-ran `EncryptCookies` and `StartSession` on Livewire's internal request and switched to a new, never-saved session (see the `Fixed` entry in `CHANGELOG.md`).

A 419 can still happen legitimately: the session expired after 2 hours of inactivity (plan 17.3), it was deleted, or the password changed on another device. Livewire's default answer is a native `confirm()` in English ("This page has expired. Would you like to refresh the page?"). It is untranslated, and choosing "Cancel" leaves a dead page. A sign-in page left open for longer than the session lifetime also fails on its first submit.

## Options considered

1. **Keep Livewire's default.** Nothing to maintain, but the untranslated prompt stays, and so does the dead page after "Cancel".
2. **Reload automatically on a 419, without a keep-alive.** The page recovers: it gets a fresh CSRF token, or the sign-in page if the session is gone. Anything typed but not yet sent is lost.
3. **Option 2 plus a keep-alive that renews the session on every visible tab.** A tab that stays open never expires, even when nobody is in front of it. On an unattended admin screen, this breaks the 2-hour inactivity rule.
4. **Option 2 plus a keep-alive gated on interaction.** The keep-alive pings only if the tab is visible *and* the person has interacted with the page (pointer, key, wheel, touch) since the previous ping.

## Decision

Option 4, on both panels, from a Blade partial injected with the `HEAD_END` render hook. The script carries the Vite CSP nonce when there is one.

- **Auto-reload.** A Livewire request interceptor (`Livewire.interceptRequest(...).onError`) catches `419`, cancels Livewire's prompt and reloads the page. **Loop guard:** at most one automatic reload per minute per tab, recorded as a timestamp in `sessionStorage`. A second 419 inside that minute, or a browser without usable storage, falls back to Livewire's own prompt. A server-side fault can never cause a reload loop.
- **Keep-alive.** `GET /session/ping` on the admin and app hosts only (`admin.session.ping`, `app.session.ping`). It runs the `web` group, which starts and saves the session, then answers `204` with `Cache-Control: no-store, private`, throttled to 30 requests per minute. Guests may ping too, so an open sign-in page keeps a valid CSRF token. The script pings every 5 minutes, only while `document.visibilityState === 'visible'` and after an interaction since the previous ping. It sends `X-Requested-With`, so the session does not record the ping as the previous URL.
- **Inactivity rule.** A ping counts as activity, but it only follows real interaction, and the interval (5 minutes) is well below the limit (120 minutes). An unattended tab, visible or not, sends nothing and still expires after 2 hours. The impersonation window (30 minutes, plan 17.4) has its own clock and is not extended by pings.
- **No user-visible text.** The fallback is Livewire's own prompt. This ADR adds no strings to `lang/`.

## Consequences

- Only a real expiry now leads to a reload; the ping keeps an active person's session from expiring in the middle of their work.
- Each active tab writes its session row at most once every 5 minutes (the `database` session driver).
- Input that was typed but not yet sent is lost when a 419 reloads the page. This is accepted: the alternative is a dead page.
- The script uses Livewire 4's `interceptRequest` API. A Livewire major upgrade must re-check it; `tests/Feature/Panels/SessionResilienceTest.php` checks that the script is rendered.
- Diagnostics for 419s in deployments: `php artisan axispay:doctor` (read-only) and the troubleshooting tables in the Dokploy guides.
