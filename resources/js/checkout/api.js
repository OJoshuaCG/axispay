/**
 * Transport of the payment page: JSON POSTs with the CSRF token of the
 * anonymous checkout session, and the status poll. A 429 (the pay host's
 * request limit) becomes the `too_many_requests` outcome with the wait the
 * server asks for.
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

function retryAfter(value, fallback) {
    const seconds = Number(value ?? fallback);

    return Number.isFinite(seconds) && seconds > 0 ? seconds : fallback;
}

export async function postJson(url, body) {
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

    if (response.status === 429) {
        data = { ...data, outcome: 'too_many_requests', retry_after_seconds: retryAfter(data.retry_after_seconds ?? response.headers.get('Retry-After'), 60) };
    }

    return { status: response.status, data };
}

/** The polled status: `{ state }`, or `{ waitSeconds }` when over the request limit. */
export async function fetchStatus(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (response.status === 429) {
        return { waitSeconds: retryAfter(response.headers.get('Retry-After'), 5) };
    }

    return response.json();
}
