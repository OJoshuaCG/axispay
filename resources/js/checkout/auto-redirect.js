/**
 * The paid page of a link with `auto_redirect` (ADR-0064): after a short
 * countdown the payer goes back to the merchant by themselves, to the URL the
 * server wrote (with its signed proof), unless they choose to stay. The
 * countdown starts hidden and only this script shows it, so without
 * JavaScript nothing promises a redirect and the "Return" button is there.
 * The URL and the seconds come from the page; only http(s) is followed.
 */
export function initAutoRedirect() {
    const notice = document.querySelector('[data-auto-redirect]');

    if (!notice) {
        return;
    }

    const target = notice.getAttribute('data-redirect-url') ?? '';
    let remaining = Number.parseInt(notice.getAttribute('data-auto-redirect') ?? '', 10);
    let destination;

    try {
        destination = new URL(target);
    } catch {
        return;
    }

    if (!['http:', 'https:'].includes(destination.protocol) || !Number.isFinite(remaining)) {
        return;
    }

    const counter = notice.querySelector('[data-redirect-seconds]');
    let timer = null;

    const stop = () => {
        window.clearInterval(timer);
        notice.setAttribute('hidden', '');
    };
    const leave = () => {
        window.clearInterval(timer);
        window.location.assign(destination.href);
    };

    notice.querySelector('[data-redirect-stop]')?.addEventListener('click', stop);
    notice.removeAttribute('hidden');

    if (remaining <= 0) {
        leave();

        return;
    }

    timer = window.setInterval(() => {
        remaining -= 1;

        if (remaining <= 0) {
            leave();

            return;
        }

        if (counter) {
            counter.textContent = String(remaining);
        }
    }, 1000);
}
