/**
 * Payment page script (plan 11.4, DESIGN.md, ADR-0051). Presentation and
 * transport only: every decision is made by the server, every user-facing
 * text comes from the page (data-* attributes and server messages).
 *
 * Flow: Payment Element (deferred intent, card only, manual capture) →
 * elements.submit() → stripe.createConfirmationToken() → POST attempts →
 * requires_action? stripe.handleNextAction() → POST attempts/continue →
 * paid/processing → the completion page (which polls).
 */
import { initAutofocus } from '../focus';
import { appearance, prefersDark } from './appearance';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

function announce(text) {
    const live = document.getElementById('checkout-live');

    if (live && text) {
        live.textContent = '';
        window.setTimeout(() => {
            live.textContent = text;
        }, 50);
    }
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    });
    let data = {};

    try {
        data = await response.json();
    } catch {
        data = { outcome: 'error' };
    }

    // The pay host's request limit (429): the server's message and wait time.
    if (response.status === 429) {
        const seconds = Number(data.retry_after_seconds ?? response.headers.get('Retry-After') ?? 60);
        data = { ...data, outcome: 'too_many_requests', retry_after_seconds: Number.isFinite(seconds) && seconds > 0 ? seconds : 60 };
    }

    return { status: response.status, data };
}

/* ---------------- Polling of the processing panel ---------------- */

function initPolling() {
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
            const response = await fetch(poll.status, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            // Over the request limit: wait as the server asks, then keep polling.
            if (response.status === 429) {
                const seconds = Number(response.headers.get('Retry-After') ?? 5);
                window.setTimeout(tick, Math.max(poll.intervalMs, (Number.isFinite(seconds) ? seconds : 5) * 1000));

                return;
            }

            const data = await response.json();

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

/* ---------------- Checkout form ---------------- */

function initCheckout() {
    const form = document.querySelector('[data-checkout-form]');
    const configElement = document.getElementById('checkout-config');

    if (!form || !configElement || typeof window.Stripe !== 'function') {
        return;
    }

    const config = JSON.parse(configElement.textContent);
    const nonce = configElement.nonce || configElement.getAttribute('nonce') || '';
    const strings = document.querySelector('[data-checkout-strings]')?.dataset ?? {};
    const button = form.querySelector('[data-pay-button]');
    const buttonContent = button.innerHTML;
    const container = form.querySelector('[data-payment-element]');
    const skeleton = form.querySelector('[data-payment-skeleton]');
    const turnstileSlot = form.querySelector('[data-turnstile-slot]');
    const turnstileTarget = form.querySelector('[data-turnstile]');

    const stripe = window.Stripe(config.publishableKey, config.stripeAccount ? { stripeAccount: config.stripeAccount } : {});
    const elements = stripe.elements({
        mode: 'payment',
        amount: config.amount,
        currency: config.currency,
        captureMethod: 'manual',
        paymentMethodTypes: ['card'],
        locale: config.locale,
        fonts: config.fonts,
        appearance: appearance(),
    });

    const never = Object.fromEntries((config.billingDetailsNever ?? []).map((field) => [field, 'never']));
    const paymentElement = elements.create('payment', {
        fields: { billingDetails: never },
        wallets: { applePay: 'never', googlePay: 'never' },
    });

    let ready = false;
    let inFlight = false;
    let turnstileToken = null;
    let turnstileWidget = null;
    let turnstileRequired = Boolean(config.turnstile?.required);

    paymentElement.on('ready', () => {
        ready = true;
        skeleton?.remove();
        form.querySelector('[data-payment-loading]')?.remove();

        if (!config.pausedMinutes) {
            button.removeAttribute('aria-disabled');
        }
    });
    paymentElement.mount(container);

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        elements.update({ appearance: appearance() });
        turnstileWidget = null;

        if (turnstileRequired) {
            renderTurnstile(true);
        }
    });

    /* ----- Alerts ----- */

    const alerts = { error: form.querySelector('[data-checkout-alert="error"]'), warning: form.querySelector('[data-checkout-alert="warning"]') };

    function hideAlerts() {
        Object.values(alerts).forEach((alert) => alert?.setAttribute('hidden', ''));
    }

    function showAlert(message, variant = 'error') {
        hideAlerts();
        const alert = alerts[variant] ?? alerts.error;

        if (!alert || !message) {
            return;
        }

        alert.querySelector('[data-alert-text]').textContent = message;
        alert.removeAttribute('hidden');
        alert.focus();
    }

    if (config.pausedMinutes && strings.pausedMessage) {
        showAlert(strings.pausedMessage, 'warning');
    }

    /* ----- Field errors ----- */

    function fieldInput(name) {
        const id = `payer-${name.replace(/\./g, '-')}`;

        return document.getElementById(id) ?? (name === 'phone' ? document.getElementById('payer-phone') : null);
    }

    function clearFieldErrors() {
        form.querySelectorAll('[data-field-error]').forEach((element) => {
            element.classList.add('hidden');
            element.querySelector('[data-field-error-text]').textContent = '';
        });
        form.querySelectorAll('[aria-invalid="true"]').forEach((input) => {
            input.removeAttribute('aria-invalid');
            input.classList.remove('border-error');
            input.classList.add('border-line-strong');
        });
    }

    function showFieldErrors(errors) {
        let first = null;

        Object.entries(errors).forEach(([name, message]) => {
            const input = fieldInput(name);
            const error = input ? document.getElementById(`${input.id}-error`) : null;

            if (!input || !error) {
                return;
            }

            error.querySelector('[data-field-error-text]').textContent = message;
            error.classList.remove('hidden');
            input.setAttribute('aria-invalid', 'true');
            input.classList.remove('border-line-strong');
            input.classList.add('border-error');
            first ??= input;
        });

        first?.focus();

        return first !== null;
    }

    /* ----- Payer data ----- */

    function payer() {
        const data = {};

        new FormData(form).forEach((value, key) => {
            const match = key.match(/^payer\[([a-z_]+)\](?:\[([a-z0-9_]+)\])?$/);

            if (!match || typeof value !== 'string') {
                return;
            }

            if (match[2]) {
                data[match[1]] ??= {};
                data[match[1]][match[2]] = value.trim();
            } else {
                data[match[1]] = value.trim();
            }
        });

        return data;
    }

    function billingDetails(data) {
        const details = {};

        if (never.name) details.name = data.full_name ?? '';
        if (never.email) details.email = data.email ?? '';
        if (never.phone) details.phone = data.phone ?? '';
        if (never.address) {
            const address = data.billing_address ?? {};
            details.address = {
                country: address.country ?? '',
                line1: address.line1 ?? '',
                line2: address.line2 ?? '',
                city: address.city ?? '',
                state: address.state ?? '',
                postal_code: address.postal_code ?? '',
            };
        }

        return details;
    }

    /* ----- Turnstile ----- */

    function loadTurnstile() {
        if (window.turnstile) {
            return Promise.resolve(window.turnstile);
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.nonce = nonce;
            script.onload = () => resolve(window.turnstile);
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    // Turnstile is required but the page has no site key: never skip the
    // check silently; the server would refuse the payment anyway.
    function turnstileUnavailable() {
        button.setAttribute('aria-disabled', 'true');
        showAlert(strings.securityUnavailable || strings.errorMessage);
        console.error('Turnstile is required but no site key is configured.');
    }

    async function renderTurnstile(force = false) {
        turnstileRequired = true;

        if (!config.turnstile?.siteKey) {
            turnstileUnavailable();

            return;
        }

        if (!turnstileSlot) {
            return;
        }

        turnstileSlot.removeAttribute('hidden');

        if (turnstileWidget !== null && !force) {
            window.turnstile?.reset(turnstileWidget);
            turnstileToken = null;

            return;
        }

        try {
            const turnstile = await loadTurnstile();
            turnstileTarget.innerHTML = '';
            turnstileToken = null;
            turnstileWidget = turnstile.render(turnstileTarget, {
                sitekey: config.turnstile.siteKey,
                // Checked by the server with the hostname (TurnstileVerifier).
                action: 'checkout',
                appearance: 'always',
                size: turnstileTarget.clientWidth < 340 ? 'compact' : 'flexible',
                theme: prefersDark() ? 'dark' : 'light',
                language: document.documentElement.lang || 'auto',
                callback: (token) => {
                    turnstileToken = token;
                },
                'expired-callback': () => {
                    turnstileToken = null;
                },
                'error-callback': () => {
                    turnstileToken = null;
                },
            });
        } catch {
            showAlert(strings.errorMessage);
        }
    }

    if (turnstileRequired) {
        renderTurnstile();
    }

    /* ----- Submit ----- */

    function setBusy(busy, label) {
        inFlight = busy;

        if (busy) {
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('aria-busy', 'true');
            button.textContent = label ?? strings.processing;
            announce(label ?? strings.processing);
        } else {
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
            button.innerHTML = buttonContent;
        }
    }

    function showRejected(payerMessage) {
        const template = document.querySelector('template[data-template="rejected"]');
        const root = document.querySelector('[data-checkout-root]');

        if (!template || !root) {
            return;
        }

        const panel = template.content.cloneNode(true);

        if (payerMessage) {
            panel.querySelector('[data-rejected-intro]').removeAttribute('hidden');
            const quote = panel.querySelector('[data-rejected-message]');
            quote.textContent = payerMessage;
            quote.removeAttribute('hidden');
            panel.querySelector('[data-rejected-fallback]').setAttribute('hidden', '');
        }

        form.replaceWith(panel);
        const heading = root.querySelector('[data-status-panel] h1');
        document.title = `${heading.textContent.trim()} · ${document.title.split(' · ').pop()}`;
        heading.focus();
    }

    async function handle(result) {
        const data = result.data ?? {};

        if (data.turnstile_required) {
            renderTurnstile();
        }

        switch (data.outcome) {
            case 'requires_action': {
                setBusy(true, strings.phaseThreeDs);
                const next = await stripe.handleNextAction({ clientSecret: data.client_secret });

                if (next.error) {
                    announce(strings.authFailed);
                }

                setBusy(true, strings.phaseValidating);
                handle(await postJson(config.endpoints.continue, {}));

                return;
            }
            case 'invalid_fields':
                setBusy(false);
                showAlert(data.message);
                showFieldErrors(data.errors ?? {});

                return;
            case 'merchant_rejected':
                showRejected(data.payer_message);

                return;
            case 'rate_limited':
                setBusy(false);
                button.setAttribute('aria-disabled', 'true');
                showAlert(data.message, 'warning');

                return;
            case 'too_many_requests':
                setBusy(false);
                button.setAttribute('aria-disabled', 'true');
                showAlert(data.message ?? strings.errorMessage, 'warning');
                window.setTimeout(() => {
                    button.removeAttribute('aria-disabled');
                }, data.retry_after_seconds * 1000);

                return;
            case 'blocked':
            case 'unavailable':
                setBusy(false);
                button.setAttribute('aria-disabled', 'true');
                showAlert(data.message);

                return;
            case 'turnstile_required':
                setBusy(false);
                showAlert(data.message, 'warning');
                turnstileTarget?.focus();

                return;
            case 'declined':
            case 'authentication_failed':
                setBusy(false);
                showAlert(data.message);

                return;
            default:
                if (data.redirect_url) {
                    window.location.assign(data.redirect_url);

                    return;
                }

                setBusy(false);
                showAlert(data.message ?? strings.errorMessage);
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (inFlight || !ready || button.getAttribute('aria-disabled') === 'true') {
            return;
        }

        hideAlerts();
        clearFieldErrors();

        if (turnstileRequired && !config.turnstile?.siteKey) {
            turnstileUnavailable();

            return;
        }

        if (turnstileRequired && !turnstileToken) {
            showAlert(strings.turnstileMessage, 'warning');
            turnstileTarget?.focus();

            return;
        }

        setBusy(true);

        const { error: submitError } = await elements.submit();

        if (submitError) {
            setBusy(false);
            paymentElement.focus();

            return;
        }

        const data = payer();
        const { error, confirmationToken } = await stripe.createConfirmationToken({
            elements,
            params: {
                payment_method_data: { billing_details: billingDetails(data) },
                return_url: config.endpoints.complete,
            },
        });

        if (error) {
            setBusy(false);

            if (error.type === 'validation_error') {
                paymentElement.focus();
            } else {
                showAlert(error.message || strings.errorMessage);
            }

            return;
        }

        try {
            handle(await postJson(config.endpoints.attempts, { confirmation_token: confirmationToken.id, payer: data, turnstile_token: turnstileToken }));
        } catch {
            setBusy(false);
            showAlert(strings.errorMessage);
        }
    });
}

initCheckout();
initPolling();
initAutofocus();
