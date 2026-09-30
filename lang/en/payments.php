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
        'late_payment_yes' => 'Yes',
        'provider_payment_id' => 'Stripe payment',
        'created_at' => 'Started',
        'succeeded_at' => 'Paid',
    ],

    'review_reason' => [
        'closed_without_gateway' => 'Closed without Stripe (the connection lost its keys): check in Stripe that no hold remains on the card.',
        'succeeded_after_close' => 'Stripe reports this payment succeeded after the attempt was closed: check it in Stripe and refund it if it should not have been charged.',
    ],

    'decline_codes' => [
        'generic_decline' => 'Declined by the bank',
        'card_declined' => 'Card declined',
        'insufficient_funds' => 'Insufficient funds',
        'lost_card' => 'Card reported lost',
        'stolen_card' => 'Card reported stolen',
        'expired_card' => 'Expired card',
        'incorrect_cvc' => 'Incorrect security code',
        'invalid_cvc' => 'Invalid security code',
        'incorrect_number' => 'Incorrect card number',
        'invalid_number' => 'Invalid card number',
        'invalid_expiry_month' => 'Invalid expiry month',
        'invalid_expiry_year' => 'Invalid expiry year',
        'incorrect_zip' => 'Incorrect postal code',
        'processing_error' => 'Processing error',
        'do_not_honor' => 'Declined by the bank (do not honor)',
        'fraudulent' => 'Declined as possible fraud',
        'card_not_supported' => 'Card not supported',
        'currency_not_supported' => 'Currency not supported by the card',
        'card_velocity_exceeded' => 'Card limit exceeded',
        'authentication_required' => 'Bank verification required',
        'payment_intent_authentication_failure' => 'Bank verification failed',
        'pickup_card' => 'Card retained by the bank',
        'restricted_card' => 'Restricted card',
        'try_again_later' => 'Temporary decline: try again later',
        'transaction_not_allowed' => 'Transaction not allowed',
        'withdrawal_count_limit_exceeded' => 'Card usage limit exceeded',
        'invalid_account' => 'Invalid card account',
    ],

    'checkout_block' => [
        'reauthentication_required' => 'Confirm your password to continue.',
        'callout' => 'Payments paused for possible card testing until :date.',
        'callout_help' => 'The link received many declined cards in a short time. Unblock it only if you recognize the attempts.',
        'unblock' => 'Unblock payments',
        'unblock_heading' => 'Unblock this link?',
        'unblock_help' => 'Payers will be able to try again right away. A security check stays on after the next decline.',
        'unblocked' => 'Payments unblocked.',
    ],

    'privacy_notice_missing' => [
        'heading' => 'The payment page will not ask for the payer fields',
        'help' => 'Your account has no privacy notice, so the payment page collects no payer data for this link. Add your privacy notice on the Legal page (Settings) to collect it.',
        'action' => 'Go to the Legal page',
    ],

];
