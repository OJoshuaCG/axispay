<?php

declare(strict_types=1);

/*
| Payer-facing checkout (plan 11, DESIGN.md, ADR-0051). Spanish uses "tú"
| here (payer-facing); the panels keep "usted" (docs/frontend/i18n.md).
*/

return [
    'title' => [
        'pay' => 'Pay :merchant',
        'not_found' => 'Link not found',
    ],

    'summary' => [
        'pay_to' => 'Payment to :merchant',
        'total' => 'Total to pay',
        'description' => 'Description',
        'expires' => 'Expires :date',
    ],

    'payer' => [
        'heading' => 'Your details',
        'optional_label' => ':label (optional)',
        'email' => 'Email',
        'full_name' => 'Full name',
        'phone' => 'Phone',
        'phone_country' => 'Country code',
        'company_name' => 'Company',
        'tax_id' => 'Tax ID',
        'notes' => 'Notes',
        'address' => [
            'legend' => 'Billing address',
            'country' => 'Country',
            'line1' => 'Street and number',
            'line2' => 'Apartment, suite, etc.',
            'city' => 'City',
            'state' => 'State',
            'postal_code' => 'Postal code',
        ],
        'privacy' => ':merchant will receive these details. Read their :link.',
        'privacy_link' => 'privacy notice',
        'privacy_no_link' => ':merchant will receive these details.',
    ],

    'card' => [
        'heading' => 'Card',
        'loading' => 'Loading the secure card form…',
        'sandbox' => 'Sandbox: no real card is charged.',
    ],

    'pay_amount' => 'Pay :amount',
    'processing_payment' => 'Processing payment…',
    'trust' => 'Secure payment: your card details go encrypted straight to Stripe.',

    'phase' => [
        'three_ds' => 'Waiting for your bank to confirm…',
        'validating' => 'Checking your order…',
    ],

    'messages' => [
        'declined' => 'Your card was declined. Try another card or contact your bank.',
        'authentication_failed' => "We couldn't complete your bank's verification. Try again or use another card.",
        'turnstile' => "For security, please confirm you're human before trying again.",
        'rate_limited' => 'For security, payments are paused. Try again in :minutes minutes.',
        'error' => "We couldn't process the payment. No charge was made. Please try again.",
        'unavailable' => "This link isn't accepting payments right now. Contact :merchant.",
        'fix_fields' => 'Check the highlighted fields.',
    ],

    'turnstile' => [
        'label' => 'Security check',
    ],

    'states' => [
        'processing' => [
            'heading' => 'Your payment is processing',
            'body' => 'Your payment is processing. Please keep this page open.',
        ],
        'timeout' => [
            'heading' => "We don't have final confirmation yet",
            'body' => "We don't have final confirmation yet. Don't pay again: check this link later.",
            'action' => 'Check again',
        ],
        'paid' => [
            'heading' => 'Payment complete',
            'paid_on' => 'Paid on :date',
            'return' => 'Return to :merchant',
        ],
        'already_paid' => [
            'heading' => 'This payment has already been made',
        ],
        'expired' => [
            'heading' => 'This payment link has expired',
            'body' => 'This payment link has expired. Contact :merchant.',
        ],
        'canceled' => [
            'heading' => 'This payment link is no longer available',
            'body' => 'This payment link is no longer available.',
        ],
        'blocked' => [
            'heading' => "This link isn't accepting payments",
            'body' => "This link isn't accepting payments right now. Contact :merchant.",
        ],
        'rejected' => [
            'heading' => ":merchant couldn't accept this payment",
            'message_from' => 'Message from :merchant:',
            'fallback' => 'Contact :merchant for more information.',
        ],
        'voided' => 'You were not charged. Your bank may show a pending charge for a few days; it will be released automatically.',
        'not_found' => [
            'heading' => "We couldn't find this link",
            'body' => "We couldn't find this link. Check that the address is complete.",
        ],
    ],

    'contact' => 'Contact :email',

    'footer' => [
        'powered_by' => 'Powered by :platform',
        'processed_by' => 'Processed by Stripe',
        'privacy' => 'Privacy notice',
        'support' => 'Support: :email',
    ],

    'errors' => [
        'required' => 'This field is required.',
        'email' => 'Enter a valid email address.',
        'phone' => 'Enter a valid phone number.',
        'length' => 'Use between :min and :max characters.',
        'too_long' => 'Use at most :max characters.',
        'tax_id' => 'Use up to :max letters and digits.',
        'country' => 'Choose a country from the list.',
        'postal_code_mx' => 'Enter the 5-digit postal code.',
    ],

    'sandbox' => [
        'label' => 'Test card (sandbox)',
        'scenario' => [
            'success' => 'Approved',
            'decline' => 'Declined',
            'funds' => 'Insufficient funds',
            'threeds' => 'Bank verification (3D Secure)',
            'processing' => 'Slow processing',
        ],
        'bank' => [
            'title' => 'Sandbox bank',
            'body' => 'Approve this test payment?',
            'approve' => 'Approve',
            'fail' => 'Fail verification',
        ],
    ],

    'mail' => [
        'blocked' => [
            'subject' => 'A payment link was blocked (:mode mode)',
            'line' => 'The payment link :link received many declined cards in a short time and stopped accepting payments for :hours hours, as a protection against card testing.',
            'action' => 'If the declines were legitimate, you can unblock it from the link detail in the panel.',
        ],
    ],
];
