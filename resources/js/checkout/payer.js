/**
 * The payer's data of the form (`payer[field]`, `payer[billing_address][part]`)
 * and the billing details Stripe must receive for the fields the Payment
 * Element does not collect itself.
 */
export function readPayer(form) {
    const data = {};

    new FormData(form).forEach((value, key) => {
        const match = key.match(/^payer\[([a-z_]+)\](?:\[([a-z0-9_]+)\])?$/);

        if (!match || typeof value !== 'string') {
            return;
        }

        if (match[2]) {
            data[match[1]] ??= {};
            data[match[1]][match[2]] = value.trim();
        } else {
            data[match[1]] = value.trim();
        }
    });

    return data;
}

export function billingDetails(never, data) {
    const details = {};

    if (never.name) details.name = data.full_name ?? '';
    if (never.email) details.email = data.email ?? '';
    if (never.phone) details.phone = data.phone ?? '';
    if (never.address) {
        const address = data.billing_address ?? {};
        details.address = {
            country: address.country ?? '',
            line1: address.line1 ?? '',
            line2: address.line2 ?? '',
            city: address.city ?? '',
            state: address.state ?? '',
            postal_code: address.postal_code ?? '',
        };
    }

    return details;
}
