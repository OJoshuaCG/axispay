/**
 * Behavior of <x-password-input> on the Blade pages (ADR-0046). The Filament
 * twin (PasswordField) runs the same markup through Alpine instead, so this
 * module is not loaded in the panels.
 *
 * Event delegation on document: works for fields added later and needs no
 * per-field initialization.
 *
 * 1. Reveal: [data-password-toggle] buttons flip the type of the input named
 *    by aria-controls between password and text, set aria-pressed and swap
 *    the tooltip. The accessible name stays constant (toggle semantics).
 *    Focus and caret stay where they were: a mouse press does not steal
 *    focus from the input, and the selection is restored after the type
 *    change. On submit every field of the form is masked again, so the
 *    browser and password managers see a password field when they offer to
 *    save it.
 * 2. Checklist: [data-password-requirements][data-for] lists mark each
 *    [data-min] item met / not met as the person types and announce a flip
 *    (only a flip) in their polite live region.
 * 3. Confirmation: [data-password-mismatch] hints show their message once the
 *    confirmation is as long as the password and differs.
 *
 * Only the length and a boolean are computed; the value is never copied into
 * the DOM, storage or a log.
 */

function byId(id) {
    return id ? document.getElementById(id) : null;
}

function setRevealed(button, input, revealed) {
    const hadFocus = document.activeElement === input;
    let start = null;
    let end = null;

    try {
        start = input.selectionStart;
        end = input.selectionEnd;
    } catch {
        // Some input types do not expose a selection.
    }

    input.type = revealed ? 'text' : 'password';
    button.setAttribute('aria-pressed', revealed ? 'true' : 'false');
    button.title = (revealed ? button.dataset.labelHide : button.dataset.labelShow) || button.title;

    if (hadFocus && start !== null) {
        try {
            input.setSelectionRange(start, end);
        } catch {
            // Ignore: the caret simply goes to the end.
        }
    }
}

function updateRequirements(input) {
    document.querySelectorAll('[data-password-requirements]').forEach((list) => {
        if (list.dataset.for !== input.id) {
            return;
        }

        const length = input.value.length;
        const status = list.querySelector('[data-password-status]');

        list.querySelectorAll('[data-min]').forEach((item) => {
            const met = length >= Number(item.dataset.min);
            const was = item.dataset.met === 'true';
            const state = met ? list.dataset.labelMet : list.dataset.labelNotMet;

            if (met === was) {
                return;
            }

            item.dataset.met = met ? 'true' : 'false';

            const stateNode = item.querySelector('[data-requirement-state]');

            if (stateNode) {
                stateNode.textContent = state;
            }

            if (status) {
                status.textContent = `${item.dataset.label ?? ''}: ${state}`;
            }
        });
    });
}

function updateMismatch(hint) {
    const confirmation = byId(hint.dataset.for);
    const password = byId(hint.dataset.of);

    if (!confirmation || !password) {
        return;
    }

    const mismatch =
        confirmation.value !== '' &&
        confirmation.value.length >= password.value.length &&
        confirmation.value !== password.value;
    const text = mismatch ? hint.dataset.message : '';

    // Write only on change, so the live region is not re-announced per key.
    if (hint.textContent !== text) {
        hint.textContent = text;
    }
}

export function initPasswordInputs() {
    // A mouse press on the toggle must not move focus out of the input.
    document.addEventListener('mousedown', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-password-toggle]') : null;

        if (button && document.activeElement === byId(button.getAttribute('aria-controls'))) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-password-toggle]') : null;
        const input = button ? byId(button.getAttribute('aria-controls')) : null;

        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        setRevealed(button, input, button.getAttribute('aria-pressed') !== 'true');
    });

    document.addEventListener('input', (event) => {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-password-field')) {
            return;
        }

        updateRequirements(input);

        document.querySelectorAll('[data-password-mismatch]').forEach((hint) => {
            if (hint.dataset.for === input.id || hint.dataset.of === input.id) {
                updateMismatch(hint);
            }
        });
    });

    // Capture phase: mask before the form's own submit handlers run.
    document.addEventListener(
        'submit',
        (event) => {
            if (!(event.target instanceof HTMLFormElement)) {
                return;
            }

            event.target.querySelectorAll('[data-password-toggle][aria-pressed="true"]').forEach((button) => {
                const input = byId(button.getAttribute('aria-controls'));

                if (input instanceof HTMLInputElement) {
                    setRevealed(button, input, false);
                }
            });
        },
        true,
    );

    // Back/forward cache and autofill: sync lists with values already present.
    const syncAll = () => {
        document.querySelectorAll('input[data-password-field]').forEach((input) => updateRequirements(input));
    };

    window.addEventListener('pageshow', syncAll);
    syncAll();
}
