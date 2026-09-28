/**
 * Presentation helpers of the payment page: live-region announcements,
 * alerts, field errors, the Pay button, the rejection panel and the initial
 * focus. Every text comes from the page (data-* attributes) or the server.
 */

export function announce(text) {
    const live = document.getElementById('checkout-live');

    if (live && text) {
        live.textContent = '';
        window.setTimeout(() => {
            live.textContent = text;
        }, 50);
    }
}

/**
 * Move focus to the first element marked `data-autofocus` on page load, so
 * feedback rendered with the page is announced by screen readers.
 */
export function initAutofocus() {
    const target = document.querySelector('[data-autofocus]');

    if (target instanceof HTMLElement && document.activeElement === document.body) {
        target.focus();
    }
}

export function createAlerts(form) {
    const alerts = { error: form.querySelector('[data-checkout-alert="error"]'), warning: form.querySelector('[data-checkout-alert="warning"]') };

    const hide = () => Object.values(alerts).forEach((alert) => alert?.setAttribute('hidden', ''));

    const show = (message, variant = 'error') => {
        hide();
        const alert = alerts[variant] ?? alerts.error;

        if (!alert || !message) {
            return;
        }

        alert.querySelector('[data-alert-text]').textContent = message;
        alert.removeAttribute('hidden');
        alert.focus();
    };

    return { hide, show };
}

/** Field errors: the text under the field and `aria-invalid` (styled by the `checkout-fields` utility). */
export function createFieldErrors(form) {
    // Field ids follow the input names: payer-email, payer-billing_address-city,
    // payer-phone and its country select payer-phone-country.
    const ids = { phone_country: 'payer-phone-country' };
    const input = (name) => document.getElementById(ids[name] ?? `payer-${name.replace(/\./g, '-')}`);

    const clear = () => {
        form.querySelectorAll('[data-field-error]').forEach((element) => {
            element.classList.add('hidden');
            element.querySelector('[data-field-error-text]').textContent = '';
        });
        form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    };

    const show = (errors) => {
        let first = null;

        Object.entries(errors).forEach(([name, message]) => {
            const field = input(name);
            const error = field ? document.getElementById(`${field.id}-error`) : null;

            if (!field || !error) {
                return;
            }

            error.querySelector('[data-field-error-text]').textContent = message;
            error.classList.remove('hidden');
            field.setAttribute('aria-invalid', 'true');
            first ??= field;
        });

        first?.focus();

        return first !== null;
    };

    return { clear, show };
}

/** The Pay button: busy (with the phase announced), idle, or disabled. */
export function createPayButton(button, strings) {
    const content = button.innerHTML;

    return {
        busy(label) {
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('aria-busy', 'true');
            button.textContent = label ?? strings.processing;
            announce(label ?? strings.processing);
        },
        idle() {
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
            button.innerHTML = content;
        },
        disable() {
            button.setAttribute('aria-disabled', 'true');
        },
        enable() {
            button.removeAttribute('aria-disabled');
        },
        disabled: () => button.getAttribute('aria-disabled') === 'true',
    };
}

/** The merchant rejected the payment: the form is replaced by the rejection panel. */
export function showRejected(form, payerMessage) {
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
