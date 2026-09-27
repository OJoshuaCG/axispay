<?php

declare(strict_types=1);

/*
 * Payment vocabulary. Status labels are read by App\Enums\PaymentStatus::label()
 * as payments.status.<enum value>.
 */
return [

    'status' => [
        'authorized' => 'Authorized',
        'captured' => 'Captured',
        'pending' => 'Pending',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
        'disputed' => 'Disputed',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
        'expired' => 'Expired',
        // Fallback badge for a value outside the enum (see <x-payment-status>).
        'unknown' => 'Unknown status',
    ],

    // Payment attempts (plan 9.2, ADR-0050, ADR-0051), tenant panel.
    'attempt_status' => [
        'requires_payment_method' => 'Waiting for a card',
        'requires_confirmation' => 'Waiting for confirmation',
        'requires_action' => 'Bank verification',
        'requires_capture' => 'Authorized, not captured',
        'processing' => 'Processing',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
    ],

    'attempts' => [
        'section' => 'Payment attempts',
        'empty' => 'Nobody has tried to pay this link yet.',
        'id' => 'Payment',
        'status' => 'Status',
        'amount' => 'Amount',
        'card' => 'Card',
        'card_value' => ':brand •••• :last4',
        'card_country' => 'Card country',
        'failures' => 'Declines',
        'last_decline' => 'Last decline',
        'late_payment' => 'Paid after the link closed',
        'needs_review' => 'Needs review',
        'needs_review_help' => 'Closed without Stripe (the connection lost its keys): check in Stripe that no hold remains on the card.',
        'late_payment_yes' => 'Yes',
        'provider_payment_id' => 'Stripe payment',
        'created_at' => 'Started',
        'succeeded_at' => 'Paid',
    ],

    'checkout_block' => [
        'callout' => 'Payments paused for possible card testing until :date.',
        'callout_help' => 'The link received many declined cards in a short time. Unblock it only if you recognize the attempts.',
        'unblock' => 'Unblock payments',
        'unblock_heading' => 'Unblock this link?',
        'unblock_help' => 'Payers will be able to try again right away. A security check stays on after the next decline.',
        'unblocked' => 'Payments unblocked.',
    ],

    'privacy_notice_missing' => [
        'heading' => 'The payment page will not ask for the payer fields',
        'help' => 'Your account has no privacy notice URL, so the payment page collects no payer data for this link. Ask the platform administrator to add your privacy notice URL to collect it.',
    ],

];
