/**
 * Form submission guards.
 *
 * 1. Controls with aria-disabled="true" (e.g. loading buttons, which stay
 *    focusable) never activate: clicks, Enter/Space on buttons and implicit
 *    Enter-to-submit from a text field all dispatch a click, which is blocked.
 *
 * 2. Opt-in double-submit protection: forms with `data-prevent-double-submit`
 *    accept the first submit, mark themselves `data-submitting`, and put the
 *    submitter button into the loading state. Later submits are ignored.
 *
 * These are UX aids only. Payment endpoints must still be idempotent
 * (idempotency keys) on the server.
 *
 * Loading text is never hardcoded here: it comes from the button's
 * data-loading-label (rendered translated by <x-button>) or, for plain
 * buttons, from <html data-loading-label> set by the layout.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

function isInactive(element) {
    return element.closest('[aria-disabled="true"]') !== null;
}

function spinner() {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'size-icon-sm shrink-0 animate-spin');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.dataset.buttonSpinner = '';

    const circle = document.createElementNS(SVG_NS, 'circle');
    Object.entries({ cx: 12, cy: 12, r: 9, stroke: 'currentColor', 'stroke-width': 3, opacity: 0.25 })
        .forEach(([key, value]) => circle.setAttribute(key, String(value)));

    const path = document.createElementNS(SVG_NS, 'path');
    Object.entries({ d: 'M21 12a9 9 0 0 0-9-9', stroke: 'currentColor', 'stroke-width': 3, 'stroke-linecap': 'round' })
        .forEach(([key, value]) => path.setAttribute(key, String(value)));

    svg.append(circle, path);
    return svg;
}

/** Mirror the server-rendered <x-button loading> state on the client. */
function loadingLabelFor(button) {
    return button.dataset.loadingLabel || document.documentElement.dataset.loadingLabel || '';
}

export function setButtonLoading(button, label = loadingLabelFor(button)) {
    if (button.hasAttribute('data-loading')) {
        return;
    }

    button.setAttribute('aria-disabled', 'true');
    button.setAttribute('aria-busy', 'true');
    button.setAttribute('data-loading', '');
    button.setAttribute('data-client-loading', '');

    const status = document.createElement('span');
    status.className = 'sr-only';
    status.setAttribute('role', 'status');
    status.dataset.buttonStatus = '';
    status.textContent = label;

    button.prepend(spinner(), status);
}

function clearButtonLoading(button) {
    button.removeAttribute('aria-disabled');
    button.removeAttribute('aria-busy');
    button.removeAttribute('data-loading');
    button.removeAttribute('data-client-loading');
    button.querySelectorAll('[data-button-spinner], [data-button-status]').forEach((node) => node.remove());
}

export function initFormGuards() {
    // Capture phase: runs before any other click handler.
    document.addEventListener(
        'click',
        (event) => {
            if (event.target instanceof Element && isInactive(event.target)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        },
        true,
    );

    // Bubble phase on document: runs after the form's own handlers, so a submit
    // cancelled by validation code does not leave the form stuck.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-prevent-double-submit')) {
            return;
        }

        if (form.hasAttribute('data-submitting')) {
            event.preventDefault();
            return;
        }

        if (event.defaultPrevented) {
            return;
        }

        form.setAttribute('data-submitting', '');

        if (event.submitter instanceof HTMLElement) {
            setButtonLoading(event.submitter);
        }
    });

    // Restoring from the back/forward cache must not leave forms locked.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }

        document.querySelectorAll('form[data-submitting]').forEach((form) => {
            form.removeAttribute('data-submitting');
            form.querySelectorAll('[data-client-loading]').forEach(clearButtonLoading);
        });
    });
}
