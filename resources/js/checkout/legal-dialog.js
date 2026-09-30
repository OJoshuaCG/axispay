/**
 * The merchant's legal texts in a native modal <dialog> (ADR-0056,
 * docs/frontend/checkout-design.md). Progressive enhancement: each link is a
 * real link to the document's own page on the pay host, which is what
 * happens without JavaScript, without <dialog> support, or when the payer
 * opens it in a new tab (modifier keys, middle click).
 *
 * The dialog's markup and copy are rendered by the server
 * (components/checkout/legal-links.blade.php). showModal() traps focus and
 * closes on Escape natively; clicking the backdrop closes it too, and focus
 * returns to the link that opened it.
 */
export function initLegalDialogs(root = document) {
    root.querySelectorAll('[data-legal-open]').forEach((link) => {
        const dialog = document.getElementById(`legal-${link.dataset.legalOpen}`);

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            dialog.returnFocusTo = link;
            dialog.showModal();
        });
    });

    root.querySelectorAll('dialog[data-legal-dialog]').forEach((dialog) => {
        // A click on the dialog element itself (not its content) is the backdrop.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', () => {
            dialog.returnFocusTo?.focus();
            dialog.returnFocusTo = null;
        });
    });
}
