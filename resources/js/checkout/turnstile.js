/**
 * Cloudflare Turnstile on the payment page (plan 11.7): rendered only when
 * the server requires it, reset after every try (a verified token is spent).
 * Without a site key the check is never skipped: `onUnavailable` runs.
 */
import { prefersDark } from './appearance';

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function load(nonce) {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.async = true;
        script.nonce = nonce;
        script.onload = () => resolve(window.turnstile);
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

export function createTurnstile({ siteKey, slot, target, nonce, onUnavailable, onError }) {
    let widget = null;
    let token = null;
    let required = false;

    async function render(force = false) {
        required = true;

        if (!siteKey) {
            onUnavailable();

            return;
        }

        if (!slot) {
            return;
        }

        slot.removeAttribute('hidden');

        if (widget !== null && !force) {
            window.turnstile?.reset(widget);
            token = null;

            return;
        }

        try {
            const turnstile = await load(nonce);
            target.innerHTML = '';
            token = null;
            widget = turnstile.render(target, {
                sitekey: siteKey,
                // Checked by the server with the hostname (TurnstileVerifier).
                action: 'checkout',
                appearance: 'always',
                size: target.clientWidth < 340 ? 'compact' : 'flexible',
                theme: prefersDark() ? 'dark' : 'light',
                language: document.documentElement.lang || 'auto',
                callback: (value) => {
                    token = value;
                },
                'expired-callback': () => {
                    token = null;
                },
                'error-callback': () => {
                    token = null;
                },
            });
        } catch {
            onError();
        }
    }

    return {
        render,
        /** A new color scheme: the widget is drawn again. */
        redraw() {
            widget = null;

            if (required) {
                render(true);
            }
        },
        required: () => required,
        available: () => Boolean(siteKey),
        token: () => token,
        focus: () => target?.focus(),
    };
}
