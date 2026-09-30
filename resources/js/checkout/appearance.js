/**
 * Stripe Elements Appearance from the page's own tokens (docs/frontend/checkout-design.md).
 *
 * Colors are read from hidden probe elements that carry real utility
 * classes (getComputedStyle → hex), not from --color-* variables: Lightning
 * CSS compiles light-dark() into custom-property switches, so the variables
 * themselves are not plain colors. Re-run on a theme change (the payer's
 * choice, `theme:change`, or the OS under "system", ADR-0056 part C).
 */
import { effectiveTheme } from '../theme';

function toHex(color) {
    const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);

    if (!match) {
        return color;
    }

    return `#${match.slice(1, 4).map((part) => Number(part).toString(16).padStart(2, '0')).join('')}`;
}

function probe(name) {
    const element = document.querySelector(`[data-probe="${name}"]`);

    return element ? toHex(window.getComputedStyle(element).color) : undefined;
}

/** The scheme on screen, the payer's choice included (Stripe and Turnstile follow it). */
export function prefersDark() {
    return effectiveTheme() === 'dark';
}

export function appearance() {
    const lineStrong = probe('lineStrong');
    const focus = probe('focus');
    const danger = probe('danger');

    return {
        theme: prefersDark() ? 'night' : 'stripe',
        labels: 'above',
        variables: {
            colorPrimary: probe('primary'),
            colorBackground: probe('background'),
            colorText: probe('text'),
            colorTextSecondary: probe('textSecondary'),
            colorTextPlaceholder: probe('textPlaceholder'),
            colorDanger: danger,
            iconColor: probe('textSecondary'),
            fontFamily: "'Mukta', system-ui, sans-serif",
            fontSizeBase: '16px',
            spacingUnit: '4px',
            borderRadius: '8px',
            // Only the weights sent to Stripe (CheckoutFonts::STRIPE_WEIGHTS).
            fontWeightNormal: '400',
            fontWeightMedium: '500',
            fontWeightBold: '500',
        },
        rules: {
            '.Input': { border: `1px solid ${lineStrong}`, padding: '9px 12px', boxShadow: 'none' },
            '.Input:focus': { outline: `2px solid ${focus}`, outlineOffset: '2px', boxShadow: 'none' },
            '.Input--invalid': { border: `1px solid ${danger}`, boxShadow: 'none' },
        },
    };
}
