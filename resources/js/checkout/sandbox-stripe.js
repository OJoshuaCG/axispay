/**
 * Checkout sandbox, browser half (ADR-0051): a stand-in for the subset of
 * Stripe.js the payment page uses, so the whole flow runs without Stripe
 * keys. Loaded ONLY when AXISPAY_CHECKOUT_SANDBOX is on (local/testing; the
 * server refuses the flag anywhere else) and never together with the real
 * Stripe.js. Copy comes from the page (data-sandbox-labels).
 *
 * The "card" is a scenario picker; the confirmation token encodes the
 * scenario (ctoken_sandbox_<scenario>_<random>), which the server's
 * SandboxPaymentGateway reads. handleNextAction shows a "bank" dialog whose
 * answer is posted to the sandbox endpoint.
 */

const random = () => Math.random().toString(36).slice(2, 12);

class SandboxPaymentElement {
    constructor() {
        this.handlers = {};
        this.select = null;
    }

    on(event, handler) {
        this.handlers[event] = handler;
    }

    mount(target) {
        const container = typeof target === 'string' ? document.querySelector(target) : target;
        const labels = JSON.parse(container.dataset.sandboxLabels ?? '{}');
        const wrapper = document.createElement('div');
        wrapper.className = 'flex flex-col gap-stack-xs';

        const label = document.createElement('label');
        label.htmlFor = 'sandbox-scenario';
        label.className = 'text-sm font-medium text-fg';
        label.textContent = labels.label ?? 'Sandbox';

        this.select = document.createElement('select');
        this.select.id = 'sandbox-scenario';
        this.select.name = 'sandbox_scenario';
        this.select.className = 'min-h-touch w-full rounded-md border border-line-strong bg-page px-3 text-base text-fg';
        Object.entries(labels.scenarios ?? { success: 'success' }).forEach(([value, text]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            this.select.appendChild(option);
        });

        const notice = document.createElement('p');
        notice.className = 'text-sm text-fg-secondary';
        notice.textContent = labels.notice ?? '';

        wrapper.append(label, this.select, notice);
        container.appendChild(wrapper);
        this.labels = labels;
        window.setTimeout(() => this.handlers.ready?.({ elementType: 'payment' }), 150);
    }

    focus() {
        this.select?.focus();
    }

    scenario() {
        return this.select?.value ?? 'success';
    }
}

class SandboxElements {
    constructor(options) {
        this.options = options;
        this.element = null;
    }

    create() {
        this.element = new SandboxPaymentElement();

        return this.element;
    }

    update(options) {
        Object.assign(this.options, options);
    }

    async submit() {
        return {};
    }
}

function bankDialog(labels) {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'm-auto flex max-w-narrow flex-col gap-stack-md rounded-xl border border-line bg-page p-inset-lg text-fg';
        dialog.setAttribute('aria-labelledby', 'sandbox-bank-title');

        const title = document.createElement('h2');
        title.id = 'sandbox-bank-title';
        title.className = 'text-lg font-semibold';
        title.textContent = labels.title ?? 'Bank';

        const body = document.createElement('p');
        body.textContent = labels.body ?? '';

        const actions = document.createElement('div');
        actions.className = 'flex flex-wrap gap-stack-sm';

        const button = (text, approved, primary) => {
            const element = document.createElement('button');
            element.type = 'button';
            element.dataset.sandboxBank = approved ? 'approve' : 'fail';
            element.className = primary
                ? 'inline-flex min-h-touch items-center rounded-md bg-primary px-4 text-on-primary'
                : 'inline-flex min-h-touch items-center rounded-md border border-line-strong bg-page px-4 text-fg';
            element.textContent = text;
            element.addEventListener('click', () => {
                dialog.close();
                dialog.remove();
                resolve(approved);
            });

            return element;
        };

        actions.append(button(labels.approve ?? 'Approve', true, true), button(labels.fail ?? 'Fail', false, false));
        dialog.append(title, body, actions);
        document.body.appendChild(dialog);
        dialog.showModal();
    });
}

window.Stripe = function SandboxStripe() {
    let elementsInstance = null;

    return {
        elements(options) {
            elementsInstance = new SandboxElements(options);

            return elementsInstance;
        },

        async createConfirmationToken({ elements }) {
            const scenario = (elements ?? elementsInstance).element?.scenario() ?? 'success';

            return { confirmationToken: { id: `ctoken_sandbox_${scenario}_${random()}` } };
        },

        async handleNextAction({ clientSecret }) {
            const element = elementsInstance?.element;
            const config = JSON.parse(document.getElementById('checkout-config')?.textContent ?? '{}');
            const approved = await bankDialog(element?.labels?.bank ?? {});

            await fetch(config.endpoints.sandboxNextAction, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
                body: JSON.stringify({ client_secret: clientSecret, approved }),
            });

            return approved
                ? { paymentIntent: { status: 'requires_capture' } }
                : { error: { type: 'card_error', code: 'payment_intent_authentication_failure' } };
        },
    };
};
