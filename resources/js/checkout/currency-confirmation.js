/**
 * The currency confirmation of the payment page (plan 13.5, ADR-0063): a
 * card issued in Mexico paying a USD link is charged in MXN, and only after
 * the payer saw and accepted the exact amount. The server answers
 * `requires_currency_confirmation` with the quote and every text, already in
 * the page's language; this module only fills the panel the page renders and
 * shows or hides it. The decision (and the amount) is always the server's.
 */
export function createCurrencyConfirmation(form) {
    const panel = form.querySelector('[data-fx-confirmation]');
    const field = (name) => panel?.querySelector(`[data-fx-${name}]`);

    const setText = (name, text) => {
        const element = field(name);

        if (element) {
            element.textContent = text ?? '';
        }
    };

    /** The merchant's breakdown converted to MXN (ADR-0064), one row per line; the text is never read as HTML. */
    const showLines = (lines) => {
        const list = field('lines');

        if (!list) {
            return;
        }

        list.replaceChildren(
            ...lines.map((line) => {
                const row = document.createElement('li');
                const label = document.createElement('span');
                const amount = document.createElement('span');

                row.className = 'flex items-baseline justify-between gap-stack-md text-sm text-fg-secondary';
                label.className = 'min-w-0 break-words';
                amount.className = 'font-numeric shrink-0 text-fg';
                label.textContent = line.label ?? '';
                amount.textContent = line.amount_label ?? '';
                row.append(label, amount);

                return row;
            }),
        );
        list.toggleAttribute('hidden', lines.length === 0);
    };

    return {
        /** Fills and shows the panel for a quote and moves focus to it. */
        show(confirmation) {
            if (!panel) {
                return;
            }

            setText('title', confirmation.title);
            setText('intro', confirmation.intro);
            setText('original-caption', confirmation.original_caption);
            setText('original', confirmation.original_label);
            setText('amount-caption', confirmation.amount_caption);
            setText('amount', confirmation.amount_label);
            setText('rate-caption', confirmation.rate_caption);
            setText('rate', confirmation.rate_text);

            showLines(confirmation.lines ?? []);

            const markup = field('markup');

            if (markup) {
                markup.textContent = confirmation.markup_text ?? '';
                markup.toggleAttribute('hidden', !confirmation.markup_text);
            }

            panel.removeAttribute('hidden');
            panel.focus();
        },

        hide() {
            panel?.setAttribute('hidden', '');
        },

        onCancel(handler) {
            field('cancel')?.addEventListener('click', handler);
        },
    };
}
