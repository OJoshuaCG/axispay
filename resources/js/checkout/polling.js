/**
 * The processing panel polls the status (plan 11.2) every few seconds up
 * to a time limit, then offers a reload. Over the request limit it waits
 * as long as the server asks.
 */
import { fetchStatus } from './api';

export function initPolling() {
    const panel = document.querySelector('[data-poll]');

    if (!panel) {
        return;
    }

    const poll = JSON.parse(panel.getAttribute('data-poll'));
    const started = Date.now();
    const timeout = panel.querySelector('[data-poll-timeout]');
    const body = panel.querySelector('[data-poll-body]');

    panel.querySelector('[data-poll-retry]')?.addEventListener('click', () => window.location.reload());

    const tick = async () => {
        if (Date.now() - started > poll.maxMs) {
            body?.setAttribute('hidden', '');
            timeout?.removeAttribute('hidden');
            timeout?.querySelector('[role]')?.focus?.();

            return;
        }

        try {
            const data = await fetchStatus(poll.status);

            if (data.waitSeconds) {
                window.setTimeout(tick, Math.max(poll.intervalMs, data.waitSeconds * 1000));

                return;
            }

            if (data.state && data.state !== 'processing') {
                window.location.reload();

                return;
            }
        } catch {
            // Network hiccup: keep polling until the time limit.
        }

        window.setTimeout(tick, poll.intervalMs);
    };

    window.setTimeout(tick, poll.intervalMs);
}
