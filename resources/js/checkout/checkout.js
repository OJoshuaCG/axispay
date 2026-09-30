/**
 * Payment page script (plan 11.4, docs/frontend/checkout-design.md, ADR-0051). Presentation and
 * transport only: every decision is made by the server, every user-facing
 * text comes from the page (data-* attributes and server messages).
 *
 * Flow: Payment Element (deferred intent, card only, manual capture) →
 * elements.submit() → stripe.createConfirmationToken() → POST attempts →
 * requires_action? stripe.handleNextAction() → POST attempts/continue →
 * paid/processing → the completion page (which polls).
 *
 * Modules: api (transport), state, stripe-elements, turnstile, payer, ui,
 * polling, legal-dialog (the merchant's legal texts, every state), and the
 * shared theme toggle (../theme, ADR-0056 part C): this is the only script of
 * every pay-host page, so the toggle starts here, before anything can return.
 * Whatever fails along the way, the Pay button never stays busy: the
 * generic error is shown and the payer may try again.
 */
import { initThemeToggle } from '../theme';
import { appearance } from './appearance';
import { postJson } from './api';
import { initLegalDialogs } from './legal-dialog';
import { billingDetails, readPayer } from './payer';
import { initPolling } from './polling';
import { createState } from './state';
import { createStripeElements } from './stripe-elements';
import { createTurnstile } from './turnstile';
import { createAlerts, createFieldErrors, createPayButton, initAutofocus, showRejected } from './ui';

function initCheckout() {
    const form = document.querySelector('[data-checkout-form]');
    const configElement = document.getElementById('checkout-config');

    if (!form || !configElement) {
        return;
    }

    const config = JSON.parse(configElement.textContent);
    const strings = document.querySelector('[data-checkout-strings]')?.dataset ?? {};
    const alerts = createAlerts(form);
    const button = createPayButton(form.querySelector('[data-pay-button]'), strings);

    // The card form cannot load (Stripe.js blocked or failed): never a
    // skeleton forever. The message stays and Pay stays disabled.
    const cardFormUnavailable = () => {
        form.querySelector('[data-payment-skeleton]')?.remove();
        form.querySelector('[data-payment-loading]')?.remove();
        button.disable();
        alerts.show(strings.securityUnavailable || strings.errorMessage);
    };

    if (typeof window.Stripe !== 'function') {
        cardFormUnavailable();

        return;
    }

    const fieldErrors = createFieldErrors(form);
    const { stripe, elements, paymentElement, never } = createStripeElements(config);
    const { get, setState } = createState({ ready: false, inFlight: false });

    // Turnstile is required but the page has no site key: never skip the
    // check silently; the server would refuse the payment anyway.
    const turnstileUnavailable = () => {
        button.disable();
        alerts.show(strings.securityUnavailable || strings.errorMessage);
        console.error('Turnstile is required but no site key is configured.');
    };

    const turnstile = createTurnstile({
        siteKey: config.turnstile?.siteKey,
        slot: form.querySelector('[data-turnstile-slot]'),
        target: form.querySelector('[data-turnstile]'),
        nonce: configElement.nonce || configElement.getAttribute('nonce') || '',
        onUnavailable: turnstileUnavailable,
        onError: () => alerts.show(strings.errorMessage),
    });

    const setBusy = (busy, label) => {
        setState({ inFlight: busy });

        if (busy) {
            button.busy(label);
        } else {
            button.idle();
        }
    };

    paymentElement.on('ready', () => {
        setState({ ready: true });
        form.querySelector('[data-payment-skeleton]')?.remove();
        form.querySelector('[data-payment-loading]')?.remove();

        if (!config.pausedMinutes) {
            button.enable();
        }
    });
    paymentElement.on('loaderror', cardFormUnavailable);
    paymentElement.mount(form.querySelector('[data-payment-element]'));

    // The card form and the bot check paint outside our CSS: repaint them in
    // the scheme on screen when the payer picks a theme, and when the OS
    // changes while "system" (no data-theme) is in effect.
    const repaint = () => {
        elements.update({ appearance: appearance() });
        turnstile.redraw();
    };

    document.addEventListener('theme:change', repaint);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (!document.documentElement.dataset.theme) {
            repaint();
        }
    });

    if (config.pausedMinutes && strings.pausedMessage) {
        alerts.show(strings.pausedMessage, 'warning');
    }

    if (config.turnstile?.required) {
        turnstile.render();
    }

    /**
     * Shows the server's answer. Returns true when the page moves on (a
     * redirect or the rejection panel), false when the form stays.
     */
    async function handle(result) {
        const data = result.data ?? {};

        if (data.turnstile_required) {
            turnstile.render();
        }

        switch (data.outcome) {
            case 'requires_action': {
                setBusy(true, strings.phaseThreeDs);
                // A failed verification is reported by the server's answer (authentication_failed).
                await stripe.handleNextAction({ clientSecret: data.client_secret });
                setBusy(true, strings.phaseValidating);

                return handle(await postJson(config.endpoints.continue, {}));
            }
            case 'invalid_fields':
                setBusy(false);
                alerts.show(data.message);
                fieldErrors.show(data.errors ?? {});

                return false;
            case 'merchant_rejected':
                showRejected(form, data.payer_message);

                return true;
            case 'rate_limited':
            case 'blocked':
            case 'unavailable':
                setBusy(false);
                button.disable();
                alerts.show(data.message, data.outcome === 'rate_limited' ? 'warning' : 'error');

                return false;
            case 'too_many_requests':
                setBusy(false);
                button.disable();
                alerts.show(data.message ?? strings.errorMessage, 'warning');
                window.setTimeout(() => button.enable(), data.retry_after_seconds * 1000);

                return false;
            case 'turnstile_required':
                setBusy(false);
                alerts.show(data.message, 'warning');
                turnstile.focus();

                return false;
            case 'declined':
            case 'authentication_failed':
                setBusy(false);
                alerts.show(data.message);

                return false;
            case 'session_expired':
                // The anonymous session (CSRF) expired: only a reload helps.
                setBusy(false);
                button.disable();
                alerts.show(strings.sessionExpired || data.message, 'warning');

                return false;
            default:
                if (data.redirect_url) {
                    window.location.assign(data.redirect_url);

                    return true;
                }

                setBusy(false);
                // Only the checkout's own messages reach the payer, never framework text.
                alerts.show(data.outcome === 'error' && data.message ? data.message : strings.errorMessage);

                return false;
        }
    }

    async function pay() {
        const { error: submitError } = await elements.submit();

        if (submitError) {
            setBusy(false);
            paymentElement.focus();

            return false;
        }

        const payer = readPayer(form);
        const { error, confirmationToken } = await stripe.createConfirmationToken({
            elements,
            params: {
                payment_method_data: { billing_details: billingDetails(never, payer) },
                return_url: config.endpoints.complete,
            },
        });

        if (error) {
            setBusy(false);

            if (error.type === 'validation_error') {
                paymentElement.focus();
            } else {
                alerts.show(error.message || strings.errorMessage);
            }

            return false;
        }

        return handle(await postJson(config.endpoints.attempts, { confirmation_token: confirmationToken.id, payer, turnstile_token: turnstile.token() }));
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (get('inFlight') || !get('ready') || button.disabled()) {
            return;
        }

        alerts.hide();
        fieldErrors.clear();

        if (turnstile.required() && !turnstile.available()) {
            turnstileUnavailable();

            return;
        }

        if (turnstile.required() && !turnstile.token()) {
            alerts.show(strings.turnstileMessage, 'warning');
            turnstile.focus();

            return;
        }

        setBusy(true);
        let left = false;

        try {
            left = await pay();
        } catch {
            alerts.show(strings.errorMessage);
        } finally {
            // Never leave the button busy: a network error, Stripe.js or an
            // unexpected answer ends here with the generic message.
            if (!left && get('inFlight')) {
                setBusy(false);
            }
        }
    });
}

initThemeToggle();
initCheckout();
initPolling();
initAutofocus();
initLegalDialogs();
