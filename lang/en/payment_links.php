<?php

declare(strict_types=1);

/*
 * Payment links (plan 7.5, 9.1, 10.5).
 */
return [

    'singular' => 'payment link',
    'plural' => 'payment links',

    'navigation' => [
        'group' => 'Payments',
    ],

    'page' => [
        'title' => 'Payment link',
        'subheading' => [
            'test' => 'Test mode: these links never charge real cards.',
            'live' => 'Live mode: these links charge real cards.',
        ],
    ],

    'sections' => [
        'summary' => 'Summary',
        'details' => 'Details',
        'more_options' => 'More options',
    ],

    'fields' => [
        'id' => 'ID',
        'link' => 'Link',
        'expires' => 'Expires',
        'expiry' => 'Valid for',
        'status' => 'Status',
        'amount' => 'Amount',
        'amount_help' => 'Up to two decimals after the dot, for example 1,500.00.',
        'currency' => 'Currency',
        'description' => 'Description',
        'description_help' => 'Shown to the payer. Plain text, up to 500 characters.',
        'client_reference_id' => 'Your reference',
        'client_reference_id_help' => 'Optional, for example your order number. Not shown to the payer.',
        'expires_in_hours' => 'Expires in (hours)',
        'expires_in_hours_help' => 'Empty: :default hours. Maximum :max hours.',
        'locale' => 'Payment page language',
        'locale_help' => 'Empty: the account default.',
        'url' => 'Link to share',
        'expires_at' => 'Expires',
        'created_at' => 'Created',
        'created_via' => 'Created from',
        'fx_mode' => 'Currency conversion',
        'return_url' => 'Return URL',
        'payer_fields' => 'Payer fields',
        'metadata' => 'Metadata',
        'metadata_help' => 'Private data for your systems, never shown to the payer. Up to :max entries; keys with letters, digits, "_" or "-".',
        'metadata_key' => 'Key',
        'metadata_value' => 'Value',
        'metadata_add' => 'Add entry',
        'paid_at' => 'Paid',
        'canceled_at' => 'Canceled',
        'cancel_reason' => 'Cancellation reason',
        'cancel_reason_help' => 'Optional. Visible in the panel and the API, not to the payer.',
        'expired_at' => 'Expired',
        'open_count' => 'Times opened',
        'refund_status' => 'Refunds',
        'dispute_status' => 'Disputes',
    ],

    'expires_line' => 'Expires :date',

    'callout' => [
        'expired' => 'Expired on :date. It no longer accepts payments.',
        'canceled' => 'Canceled on :date. It no longer accepts payments.',
    ],

    'expiry' => [
        'hours' => ':count hours',
        'days' => ':count days',
        'custom' => 'Custom',
    ],

    'validation' => [
        'amount_required' => 'Enter the amount.',
        'description_required' => 'Enter a description for the payer.',
        'amount_format' => 'Enter the amount with up to two decimals, for example 1,500.00.',
    ],

    'url_help' => 'Anyone with this link can see the description and pay. Share it only with the payer.',
    'copied' => 'Copied',
    'copy_failed' => 'Could not copy. Select the text and copy it by hand.',

    'status' => [
        'active' => 'Active',
        'processing' => 'Processing',
        'paid' => 'Paid',
        'expired' => 'Expired',
        'canceled' => 'Canceled',
    ],

    'fx_mode' => [
        'none' => 'None',
        'banxico_fix' => 'Banxico FIX rate',
        'fixed' => 'Fixed rate',
    ],

    'refund_status' => [
        'none' => 'None',
        'partial' => 'Partial',
        'full' => 'Full',
    ],

    'dispute_status' => [
        'none' => 'None',
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
    ],

    'created_via' => [
        'api' => 'API',
        'panel' => 'Panel',
    ],

    'locale' => [
        'es' => 'Spanish',
        'en' => 'English',
    ],

    'payer_field' => [
        'email' => 'E-mail',
        'full_name' => 'Full name',
        'phone' => 'Phone',
        'company_name' => 'Company',
        'billing_address' => 'Billing address',
        'tax_id' => 'Tax ID',
        'notes' => 'Notes',
    ],

    'payer_requirement' => [
        'hidden' => 'Hidden',
        'optional' => 'Optional',
        'required' => 'Required',
    ],

    'empty' => [
        'heading' => 'No payment links yet',
        'description' => 'Create a link here or from the API and share it with the payer.',
    ],

    'actions' => [
        'create' => 'Create payment link',
        'create_submit' => 'Create link',
        'create_help' => [
            'test' => 'Test mode: the link will not charge real cards.',
            'live' => 'Live mode: the link will charge a real card.',
        ],
        'copy_link' => 'Copy link',
        'copy_id' => 'Copy ID',
        'copy_link_tooltip' => 'Copies the link to share with the payer',
        'cancel' => 'Cancel link',
        'cancel_heading' => 'Cancel this payment link?',
        'cancel_help' => 'The :amount link (":description") will be canceled. The payer will no longer be able to pay it. This cannot be undone.',
        'cancel_keep' => 'Keep link',
        'cancel_submit' => 'Cancel link',
    ],

    'notifications' => [
        'created' => 'Payment link created',
        'canceled' => 'Payment link canceled',
    ],

];
