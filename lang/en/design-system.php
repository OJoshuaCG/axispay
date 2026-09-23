<?php

declare(strict_types=1);

/*
 * Copy for resources/views/design-system.blade.php (local-only developer
 * preview). Its prose and sample component copy are translated so the page can
 * be reviewed in every locale (long Spanish strings exercise wrapping).
 * Technical identifiers (class names, token names, variant names) are never
 * translated: they are code.
 */
return [

    'title' => 'Design system',
    'lead' => 'Tokens and components in one place. Switch the theme and language above to review every combination.',

    'primitives' => [
        'title' => 'Primitive colors',
        'lead' => 'Raw scales. Use semantic tokens in components.',
        'status_group' => 'Status (amber / red)',
    ],

    'semantic' => [
        'title' => 'Semantic tokens',
        'lead' => 'These swap automatically with the theme.',
    ],

    'typography' => [
        'title' => 'Typography',
        'sample' => 'Secure payments',
        'primary_text' => 'Primary text (text-fg)',
        'secondary_text' => 'Secondary text (text-fg-secondary)',
        'muted_text' => 'Muted text (text-fg-muted) — non-essential only',
        'links' => 'An inline :prose and a :visited.',
        'prose_link' => 'prose link',
        'visited_link' => 'visited link',
    ],

    'foundations' => [
        'label' => 'Spacing, radius and shadows',
        'spacing' => 'Spacing',
        'radius' => 'Radius',
        'shadows' => 'Shadows',
    ],

    'buttons' => [
        'title' => 'Buttons',
        'small' => 'Small',
        'medium' => 'Medium',
        'large' => 'Large',
        'with_icon' => 'With icon',
        'disabled' => 'Disabled',
        'processing' => 'Processing',
        'as_link' => 'as link',
        'link_button' => 'Link button',
        'disabled_link' => 'Disabled link',
        'icon_only' => 'icon only',
        'retry_payment' => 'Retry payment',
        'delete_card' => 'Delete card',
        'settings' => 'Settings',
        'guard_title' => 'Double-submit guard',
        'guard_lead' => 'The form below uses data-prevent-double-submit. The first submit switches the button to its loading state (still focusable, aria-disabled + aria-busy); further submits are ignored.',
        'pay_now' => 'Pay now',
        'processing_payment' => 'Processing payment',
    ],

    'inputs' => [
        'title' => 'Inputs',
        'email' => 'Email',
        'email_placeholder' => 'you@example.com',
        'card_number' => 'Card number',
        'card_hint' => '16 digits, no spaces.',
        'amount' => 'Amount',
        'amount_error' => 'Amount must be greater than 20.00.',
        'disabled' => 'Disabled',
        'not_editable' => 'Not editable',
        'price' => 'Price',
        'price_hint' => 'Amount charged to the customer.',
        'fee' => 'Processing fee (read-only)',
    ],

    'badges' => [
        'title' => 'Badges',
        'draft' => 'Draft',
        'paid' => 'Paid',
        'pending' => 'Pending',
        'declined' => 'Declined',
        'refunded' => 'Refunded',
    ],

    'money' => [
        'title' => 'Money',
        'amounts' => 'Amounts',
        'amounts_lead' => 'Formatted in the current language unless a locale is forced.',
        'payment' => 'Payment',
        'refund' => 'Refund',
        'fee_forced' => 'Fee (EUR, forced de_DE)',
        'minor_units' => 'Minor units (CLP, forced es_CL)',
        'balance' => 'Balance (unsigned)',
        'status' => 'Payment status',
        'dates' => 'Dates',
        'created' => 'Created',
    ],

    'alerts' => [
        'title' => 'Alerts',
        'success_title' => 'Payment received',
        'success_body' => 'The receipt was sent to the customer.',
        'warning_title' => 'Verification pending',
        'warning_body' => 'Additional information may be required.',
        'error_title' => 'Payment declined',
        'error_body' => 'The card issuer declined the transaction.',
        'info_body' => 'Settlements are processed every business day.',
    ],

    'cards' => [
        'title' => 'Cards',
        'default' => 'Default card',
        'default_body' => 'Surface, border and shadow-xs.',
        'elevated' => 'Elevated card',
        'elevated_body' => 'shadow-md and large padding.',
        'with_slots' => 'With header and footer',
        'with_slots_body' => 'Header and footer slots share the card padding.',
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
    ],

    'icons' => [
        'title' => 'Icons',
        'secure' => 'Secure',
        'meaningful' => 'Meaningful icon with an accessible label.',
    ],

    'feature' => [
        'title' => 'Feature (decorative)',
        'body' => 'Brand protagonist color. Decorative only: never for buttons or links.',
    ],

];
