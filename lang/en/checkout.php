<?php

declare(strict_types=1);

/*
| Payer-facing checkout (plan 11, DESIGN.md, ADR-0051). Spanish uses "tú"
| here (payer-facing); the panels keep "usted" (docs/frontend/i18n.md).
*/

return [
    'title' => [
        'pay' => 'Pay :merchant',
        'pay_short' => 'Pay',
        'too_many_requests' => 'Too many requests',
        'not_found' => 'Link not found',
    ],

    'summary' => [
        'pay_to' => 'Payment to :merchant',
        'total' => 'Total to pay',
        'breakdown' => 'Payment breakdown',
        'expires' => 'Expires :date',
    ],

    'redirect' => [
        'countdown' => 'We will take you back to :merchant in :seconds s.',
        'stop' => 'Stay here',
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
        'rate_limited' => '{1} For security, payments are paused. Try again in :minutes minute.|[2,*] For security, payments are paused. Try again in :minutes minutes.',
        'error' => "We couldn't process the payment. No charge was made. Please try again.",
        'unavailable' => "This link isn't accepting payments right now. Contact :merchant.",
        'too_many_requests' => '{1} Too many requests. Try again in :seconds second.|[2,*] Too many requests. Try again in :seconds seconds.',
        'session_expired' => 'Reload the page to continue.',
        'security_unavailable' => 'Payments on this page are unavailable right now. Please try again later.',
        'fix_fields' => 'Check the highlighted fields.',
        'conversion_unavailable' => ':merchant cannot charge this amount in USD to cards issued in Mexico. No charge was made. Contact :merchant.',
    ],

    /*
    | USD to Mexican peso conversion (plan 13, ADR-0063): the page legend and
    | the confirmation screen before the charge.
    */
    'fx' => [
        'date_format' => 'Y-m-d',
        'legend' => 'If you pay with a card issued in Mexico, you will be charged :amount. Exchange rate: :rate. :markup',
        'source' => [
            'banxico_fix' => 'Banxico FIX of :date',
            'merchant' => 'exchange rate set by the merchant',
        ],
        'markup' => 'Includes a merchant adjustment of :percent %.',
        'confirm' => [
            'title' => 'Confirm the charge in Mexican pesos',
            'intro' => 'Your card was issued in Mexico. This payment will be charged in Mexican pesos.',
            'original' => 'Original amount',
            'amount' => 'Amount to be charged',
            'rate' => 'Exchange rate applied',
            'pay' => 'Pay :amount',
            'cancel' => 'Cancel',
        ],
    ],

    'turnstile' => [
        'label' => 'Security check',
    ],

    'states' => [
        'processing' => [
            'heading' => 'Your payment is processing',
            'body' => 'Please keep this page open.',
        ],
        'timeout' => [
            'heading' => "We don't have final confirmation yet",
            'body' => "Don't pay again: check this link later.",
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
            'body' => 'Contact :merchant.',
        ],
        'canceled' => [
            'heading' => 'This payment link is no longer available',
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
        'too_many_requests' => [
            'heading' => 'Too many requests',
            'body' => 'Please wait a moment and reload this page.',
        ],
        'not_found' => [
            'heading' => "We couldn't find this link",
            'body' => 'Check that the address is complete.',
        ],
    ],

    'error_pages' => [
        404 => [
            'heading' => "We couldn't find this page",
            'body' => 'Check that the address is complete.',
        ],
        419 => [
            'heading' => 'Your session expired',
            'body' => 'Reload the page to continue.',
        ],
        429 => [
            'heading' => 'Too many requests',
            'body' => 'Please wait a moment and reload this page.',
        ],
        500 => [
            'heading' => 'Something went wrong',
            'body' => 'You were not charged. Please try again in a few minutes.',
        ],
        503 => [
            'heading' => "We'll be right back",
            'body' => "We're doing maintenance. Please try again in a few minutes.",
        ],
    ],

    'contact' => 'Contact :email',

    'footer' => [
        'powered_by' => 'Powered by :platform',
        'processed_by' => 'Processed by Stripe',
        'help' => 'Questions about your payment? Email :email',
    ],

    'legal' => [
        'nav' => 'Legal documents',
        'link' => [
            'privacy' => 'Privacy notice',
            'terms' => 'Terms',
        ],
        'title' => [
            'privacy' => 'Privacy notice',
            'terms' => 'Terms and conditions',
        ],
        'close' => 'Close',
        'new_tab' => '(opens in a new tab)',
        'back' => 'Back to the payment',
        'external' => ':merchant publishes this document on their website.',
        'open_external' => 'Open the document',
        'platform_title' => 'Legal information',
        'platform_intro' => 'The privacy notice and terms and conditions of :platform, the platform behind this payment page.',
        'platform_external' => 'Published on another website.',
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
            'foreign' => 'Approved foreign card (US)',
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
            'subject' => 'A payment link was blocked (:mode)',
            'line' => '{1} The payment link :link received many declined cards in a short time and stopped accepting payments for :hours hour, as a protection against card testing.|[2,*] The payment link :link received many declined cards in a short time and stopped accepting payments for :hours hours, as a protection against card testing.',
            'button' => 'View the link',
            'action' => 'If the declines were legitimate, you can unblock it from the link detail in the panel.',
        ],
    ],
];
