/**
 * Move focus to the first element marked `data-autofocus` on page load
 * (e.g. <x-alert focus>), so feedback rendered with the initial page is
 * announced by screen readers. The element must be focusable (tabindex="-1").
 */
export function initAutofocus() {
    const target = document.querySelector('[data-autofocus]');

    if (target instanceof HTMLElement && document.activeElement === document.body) {
        target.focus();
    }
}
