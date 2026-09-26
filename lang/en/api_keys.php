<?php

declare(strict_types=1);

/*
 * API keys (plan 10.2, 17.3).
 */
return [

    'singular' => 'API key',
    'plural' => 'API keys',

    'navigation' => [
        'group' => 'Settings',
    ],

    'page' => [
        'subheading' => [
            'test' => 'Keys for test mode. They only reach test data and never move real money.',
            'live' => 'Keys for live mode. They create real payment links: keep them on your server only.',
        ],
    ],

    'fields' => [
        'summary' => 'Key',
        'name' => 'Name',
        'name_help' => 'Where the key is used, for example "Online store".',
        'key' => 'Key',
        'scopes' => 'Permissions',
        'scopes_help' => 'Give the key only what the integration needs.',
        'status' => 'Status',
        'last_used_at' => 'Last used',
        'created_at' => 'Created',
    ],

    'never_used' => 'Never',
    'scope_all' => 'All permissions',

    'status' => [
        'active' => 'Active',
        'revoked' => 'Revoked',
        'expired' => 'Expired',
    ],

    'scope' => [
        'links_create' => 'Create payment links',
        'links_read' => 'View payment links',
        'links_cancel' => 'Cancel payment links',
        'payments_read' => 'View payments',
        'refunds_create' => 'Create refunds',
        'refunds_read' => 'View refunds',
        'events_read' => 'View events',
    ],

    'filters' => [
        'status' => 'Status',
        'active_only' => 'Active keys',
        'revoked_only' => 'Revoked keys',
        'all' => 'All keys',
    ],

    'empty' => [
        'heading' => 'No API keys yet',
        'description' => 'Create a key to connect your system to the API. Keys belong to the account, not to a person.',
    ],

    'actions' => [
        'create' => 'Create API key',
        'create_submit' => 'Create key',
        'create_help' => [
            'test' => 'The key will work in test mode only.',
            'live' => 'The key will work in live mode and can create real payment links. Every owner will get an e-mail.',
        ],
        'revoke' => 'Revoke',
        'revoke_heading' => 'Revoke this API key?',
        'revoke_submit' => 'Revoke key',
        'revoke_help' => 'The key ":name" (:key) will be revoked. It was last used :since: integrations that use it will fail at once. This cannot be undone.',
        'revoke_help_unused' => 'The key ":name" (:key) will be revoked. It has never been used. This cannot be undone.',
        'revoke_live_note' => 'The owners will be told by e-mail.',
    ],

    'issued' => [
        'heading' => 'Your new API key',
        'description' => 'Store it in your server configuration or secret manager.',
        'warning_heading' => 'Copy it now',
        'warning' => [
            'test' => 'This is the only time the full key is shown. If you lose it, revoke it and create a new one.',
            'live' => 'This is the only time the full key is shown. Anyone with this key can create real charges: keep it on your server only. If you lose it, revoke it and create a new one.',
        ],
        'copy' => 'Copy key',
        'copied' => 'Key copied',
        'copy_failed' => 'Could not copy. Select the key and copy it by hand.',
        'done' => 'I have copied the key',
    ],

    'notifications' => [
        'revoked' => 'API key revoked',
        'created' => 'Key ":name" created (:key)',
    ],

    'validation' => [
        'name_required' => 'Give the key a name.',
        'scopes_required' => 'Choose at least one permission.',
    ],

    'errors' => [
        'tenant_read_only' => 'Your account is read-only in its current state: new API keys cannot be created. You can still revoke keys.',
        'invalid_name' => 'Give the key a name of up to 100 characters.',
        'no_scopes' => 'Choose at least one permission.',
        'reauthentication_required' => 'Confirm your password to continue.',
    ],

    'mail' => [
        'live_revoked' => [
            'subject' => 'A live API key was revoked',
            'line' => ':user revoked the live API key ":name". Integrations that still use it now fail.',
            'review' => 'If you did not expect this, check Settings → API keys and the audit log.',
        ],
        'live_created' => [
            'subject' => 'A live API key was created',
            'line' => ':user created the live API key ":name". It can create real payment links.',
            'review' => 'If you do not recognize this, revoke the key in Settings → API keys.',
        ],
    ],

];
