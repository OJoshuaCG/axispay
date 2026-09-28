/**
 * Stripe Elements for the payment page (ADR-0051): deferred intent, card
 * only (matching the server's `allowed_payment_method_types`), manual
 * capture, no wallets and no Link; the page's typeface and colors.
 */
import { appearance } from './appearance';

export function createStripeElements(config) {
    const stripe = window.Stripe(config.publishableKey, config.stripeAccount ? { stripeAccount: config.stripeAccount } : {});
    const elements = stripe.elements({
        mode: 'payment',
        amount: config.amount,
        currency: config.currency,
        captureMethod: 'manual',
        allowedPaymentMethodTypes: ['card'],
        locale: config.locale,
        fonts: config.fonts,
        appearance: appearance(),
    });

    const never = Object.fromEntries((config.billingDetailsNever ?? []).map((field) => [field, 'never']));
    const paymentElement = elements.create('payment', {
        fields: { billingDetails: never },
        wallets: { applePay: 'never', googlePay: 'never', link: 'never' },
    });

    return { stripe, elements, paymentElement, never };
}
