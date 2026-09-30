<?php

declare(strict_types=1);

/*
| Legal texts in the panels (ADR-0056): the merchant's privacy notice and
| terms (tenant panel) and the platform's own (platform panel). Payer-facing
| copy lives in checkout.php.
*/

return [

    'navigation' => [
        'group' => 'Settings',
    ],

    'page' => [
        'title' => 'Legal',
        'tenant_subheading' => 'Your privacy notice and terms and conditions, shown to payers on your payment pages.',
        'platform_subheading' => 'The platform\'s privacy notice and terms and conditions, shown on the public legal page linked from every payment page.',
    ],

    'kind' => [
        'privacy' => 'Privacy notice',
        'terms' => 'Terms and conditions',
    ],

    'format' => [
        'text' => 'Write the text here',
        'url' => 'Link to a page',
    ],

    'sections' => [
        'tenant_privacy' => 'Required to ask payers for their details: without it, the payment page collects no payer fields. Payers open it from the payment page.',
        'tenant_terms' => 'Optional. Payers open it from the payment page.',
        'platform_privacy' => 'Shown on the public legal page of the payment host.',
        'platform_terms' => 'Shown on the public legal page of the payment host.',
    ],

    'status' => [
        'not_set' => 'Not set.',
        'link' => 'Published as a link:',
        'text' => 'Published as text. Preview:',
        'no_privacy_warning' => 'Without a privacy notice, your payment pages do not ask payers for their details, even when a link is set up to collect them.',
    ],

    'fields' => [
        'format' => 'How to publish it',
        'body' => 'Text',
        'body_help' => 'Simple Markdown: blank lines separate paragraphs; # for headings, - for lists, **bold**, [text](https://example.com) for links. HTML is not allowed. Up to :max characters.',
        'url' => 'Page address',
        'url_help' => 'The full address, starting with https://. Payers open it in a new tab.',
    ],

    'actions' => [
        'edit' => 'Edit',
        'set' => 'Add',
        'edit_heading' => 'Edit: :document',
        'save' => 'Save',
        'remove' => 'Remove',
        'remove_heading' => 'Remove: :document',
        'remove_help' => 'Payers will no longer see it.',
        'remove_privacy_help' => 'Payers will no longer see it, and your payment pages will stop asking for payer details.',
    ],

    'notifications' => [
        'saved' => 'Saved: :document.',
        'removed' => 'Removed: :document.',
    ],

    'errors' => [
        'empty_body' => 'Write the text of the document.',
        'body_too_long' => 'Use at most :max characters.',
        'invalid_url' => 'Enter a full address that starts with http:// or https://, up to :max_url characters.',
        'reauthentication_required' => 'Confirm your password to change the legal documents.',
    ],

];
