<?php

declare(strict_types=1);

return [

    'navigation' => [
        'group' => 'Settings',
    ],

    'provider' => [
        'stripe' => 'Stripe',
    ],

    'mode' => [
        'test' => 'test mode',
        'live' => 'live mode',
    ],

    'method' => [
        'platform_onboarding' => 'Created or connected with Stripe',
        'oauth' => 'Connected with OAuth',
        'api_key' => 'Your API keys (advanced)',
    ],

    'status' => [
        'onboarding' => 'Onboarding',
        'active' => 'Active',
        'restricted' => 'Restricted',
        'invalid_credentials' => 'Invalid keys',
        'disconnected' => 'Disconnected',
    ],

    'disconnect_reason' => [
        'user_requested' => 'Disconnected by a user',
        'deauthorized' => 'Removed from the Stripe account',
    ],

    'page' => [
        'title' => 'Stripe connection',
        'subheading' => [
            'test' => 'You are viewing test mode. Test and live connections are separate.',
            'live' => 'You are viewing live mode. Real payments go to this account.',
        ],
    ],

    'connect' => [
        'onboarding' => [
            'heading' => 'Create or connect with Stripe (recommended)',
            'description' => 'Stripe guides you through creating your account or connecting the one you already have. Stripe collects your business details and you keep full access to your Stripe dashboard.',
        ],
        'api_key' => [
            'heading' => 'Advanced: use my API keys',
            'description' => 'For businesses that already have a verified Stripe account and prefer to give us a restricted key.',
            'warning_heading' => 'We will store a credential of your Stripe account',
            'warning' => 'Only use this option if you cannot use the recommended one. You must create a restricted key with the minimum permissions and rotate it if you suspect it was exposed.',
        ],
        'none_enabled' => 'No connection method is available right now. Contact support.',
    ],

    'details' => [
        'heading' => 'Connection details',
        'excessive_heading' => 'Your key can do more than it needs',
        'excessive_body' => 'It also has these permissions: :permissions. We recommend creating a new restricted key without them and updating it here.',
    ],

    'callout' => [
        'onboarding' => [
            'heading' => 'Onboarding is not finished',
            'body' => 'You cannot receive payments yet. Continue the onboarding in Stripe; this page updates when Stripe confirms your account.',
        ],
        'restricted' => [
            'heading' => 'Stripe paused payments on this account',
            'body' => 'Payment links cannot be created until Stripe enables charges again. Review the pending requirements below.',
        ],
        'invalid_credentials' => [
            'heading' => 'Your API keys stopped working',
            'body' => 'Stripe rejected the stored key. It may have been revoked or changed. Update your keys to receive payments again.',
        ],
    ],

    'fields' => [
        'status' => 'Status',
        'method' => 'Connection method',
        'account' => 'Stripe account',
        'country' => 'Country',
        'default_currency' => 'Default currency',
        'charges_enabled' => 'Can receive payments',
        'payouts_enabled' => 'Can receive payouts',
        'last_synced_at' => 'Last updated from Stripe',
        'restricted_key' => 'Restricted key',
        'last_health_check_at' => 'Last key check',
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'requirements' => [
        'heading' => 'Pending requirements',
        'description' => 'Stripe asks for this information when you continue the onboarding.',
        'intro' => 'Stripe still needs :count item(s):',
        'deadline' => 'Stripe asks for them before :date.',
        'reason' => [
            'information_needed' => 'Stripe needs more information before it can enable payments.',
            'rejected' => 'Stripe rejected this account. Contact Stripe support for details.',
            'under_review' => 'Stripe is reviewing this account.',
            'paused' => 'Stripe paused this account. Check your Stripe dashboard for details.',
        ],
    ],

    'actions' => [
        'start_onboarding' => 'Connect with Stripe',
        'start_onboarding_help' => 'You will continue on Stripe\'s website and come back here when you finish.',
        'go_to_stripe' => 'Go to Stripe',
        'country_help' => 'The country of your business. It cannot be changed later.',
        'continue_onboarding' => 'Continue onboarding',
        'continue_onboarding_help' => 'You will continue on Stripe\'s website where you left off.',
        'refresh' => 'Refresh status',
        'connect_api_key' => 'Connect with API keys',
        'update_keys' => 'Update keys',
        'disconnect' => 'Disconnect',
        'disconnect_heading' => 'Disconnect Stripe?',
        'disconnect_help' => [
            'platform_onboarding' => 'Payments stop for this mode. Your Stripe account stays yours; to connect again, you start a new connection.',
            'oauth' => 'Payments stop for this mode and the platform loses access to your Stripe account.',
            'api_key' => 'Payments stop for this mode. We delete the webhook we created in your Stripe account and erase your stored keys. You can also delete the restricted key in Stripe.',
        ],
    ],

    // Help for the api_key method: the restricted key's permissions and how
    // to create it. The lists come from StripeKeyPermissions (ADR-0047).
    'permissions_help' => [
        'action' => 'View required permissions',
        'heading' => 'Permissions of the restricted key',
        'form_heading' => 'Which permissions does the key need?',
        'close' => 'Close',
        'intro' => 'Create a restricted key in Stripe with exactly these permissions. We check them when you save the key.',
        'steps_heading' => 'How to create the key',
        'steps' => [
            '1' => 'In the Stripe Dashboard, make sure you are in the same mode as this panel (:mode).',
            '2' => 'Go to Developers → API keys → "Create restricted key".',
            '3' => 'Give it a name, for example "AxisPay".',
            '4' => 'Set the permissions in the table below and leave everything else at "None".',
            '5' => 'Create the key and copy it (it starts with rk_).',
            '6' => 'Also copy the publishable key (pk_) of the same account and mode.',
            '7' => 'Paste both keys into the form.',
        ],
        'table_heading' => 'Required permissions',
        'columns' => [
            'resource' => 'Resource',
            'level' => 'Permission',
            'why' => 'Why we need it',
            'identifier' => 'Technical name',
        ],
        'level' => [
            'read' => 'Read',
            'write' => 'Write',
        ],
        'resources' => [
            'connected_account_read' => 'Accounts',
            'token_read' => 'Tokens',
            'webhook_write' => 'Webhook endpoints',
            'payment_intent_write' => 'PaymentIntents',
            'charge_write' => 'Charges and refunds',
            'charge_read' => 'Charges and refunds',
            'dispute_read' => 'Disputes',
            'event_read' => 'Events',
            'payment_method_read' => 'PaymentMethods',
            'confirmation_token_read' => 'ConfirmationTokens',
            'payout_write' => 'Payouts',
            'transfer_write' => 'Transfers',
            'balance_read' => 'Balance',
        ],
        'reasons' => [
            'connected_account_read' => 'Read your account country and whether it can accept charges.',
            'token_read' => 'Confirm that both keys belong to the same account.',
            'webhook_write' => 'Create the endpoint that tells us about changes to your account.',
            'payment_intent_write' => 'Charge your customers\' cards.',
            'charge_write' => 'Issue refunds.',
            'charge_read' => 'Reconcile payments with Stripe.',
            'dispute_read' => 'Follow disputes (chargebacks).',
            'event_read' => 'Reconcile payments with Stripe.',
            'payment_method_read' => 'Know the card\'s country for currency rules.',
            'confirmation_token_read' => 'Complete the payment page\'s card payment.',
        ],
        'dangerous' => [
            'heading' => 'Do not grant these permissions',
            'body' => 'We never need them, and they can move or expose your money. If the key has them, we will warn you, and in live mode you must confirm before we use it.',
        ],
        'labels_note' => 'The labels in the Stripe Dashboard may differ slightly: match each permission by its resource name.',
        'docs_link' => 'Stripe guide to restricted keys',
        'new_tab' => '(opens in a new tab)',
    ],

    'api_key' => [
        'help' => 'Paste the keys of your Stripe account for :mode. You find them in the Stripe dashboard, under Developers > API keys.',
        'restricted_key' => 'Restricted key',
        'restricted_key_help' => 'Starts with rk_. Never paste a secret key (sk_). After saving, only its last four characters are shown.',
        'publishable_key' => 'Publishable key',
        'publishable_key_help' => 'Starts with pk_ and belongs to the same Stripe account and mode.',
        'show_key' => 'Show key',
        'hide_key' => 'Hide key',
        'accept_excessive' => 'I understand that this key also has these permissions and want to use it anyway: :permissions',
        'risk' => [
            'heading' => 'Before you continue',
            'body' => 'The platform will store an encrypted credential of your Stripe account. You are responsible for giving the restricted key only the permissions listed in the guide and for rotating it if you suspect it was exposed. We check the key every day and notify you if it stops working.',
            'accept' => 'I accept this notice',
        ],
        'errors' => [
            'secret_key_not_allowed' => 'Secret keys (sk_) are never accepted: they give full control of your account. Create a restricted key (rk_) in Stripe with the permissions from the guide.',
            'not_a_restricted_key' => 'This is not a Stripe restricted key. It must start with rk_test_ or rk_live_.',
            'invalid_publishable_key' => 'This is not a Stripe publishable key. It must start with pk_test_ or pk_live_.',
            'key_modes_differ' => 'The restricted key and the publishable key belong to different modes (test and live).',
            'panel_mode_mismatch' => 'These keys are for the other mode. Switch the panel to that mode or use the keys of this mode.',
            'key_rejected' => 'Stripe rejected the restricted key. Check that you copied it completely and that it has not been deleted.',
            'account_not_readable' => 'The restricted key cannot read your account details. Give it read access to Accounts.',
            'country_not_allowed' => 'Your Stripe account is in a country we do not support yet (:details).',
            'publishable_key_rejected' => 'Stripe rejected the publishable key. Check that you copied it completely.',
            'publishable_key_other_account' => 'The publishable key belongs to a different Stripe account than the restricted key.',
            'missing_permissions' => 'The restricted key is missing permissions: :details. Edit the key in Stripe and try again.',
            'excessive_permissions_not_confirmed' => 'This key also has permissions we do not need (:details). Create a more limited key, or confirm below that you want to use it.',
            'account_already_linked' => 'This Stripe account is already connected to another account on the platform.',
            'account_uses_connect' => 'This Stripe account is linked to the platform through Stripe Connect. It cannot also be connected with API keys.',
            'key_already_linked' => 'This key is already in use. Create a new restricted key for this connection.',
            'different_account' => 'These keys belong to a different Stripe account. To change accounts, disconnect and connect again.',
            'webhook_endpoint_failed' => 'We could not create the webhook in your Stripe account, so the connection was not saved. Try again.',
            'gateway_unavailable' => 'We could not reach Stripe. Nothing was saved. Try again in a few minutes.',
        ],
    ],

    'errors' => [
        'method_disabled' => 'This connection method is not available.',
        'already_connected' => 'Stripe is already connected in this mode. Disconnect it first to use another method.',
        'country_not_allowed' => 'This country is not supported yet.',
        'not_onboarding' => 'This connection has no pending onboarding.',
        'not_api_key' => 'This connection does not use API keys.',
        'risk_not_acknowledged' => 'You must accept the risk notice to continue.',
        'gateway_unavailable' => 'We could not reach Stripe. Try again in a few minutes.',
        'gateway_refused' => 'Stripe refused the request. Try again or contact support.',
        'reauthentication_required' => 'Confirm your identity to continue.',
    ],

    'notifications' => [
        'refreshed' => 'Status updated from Stripe',
        'api_key_connected' => 'Stripe connected with your API keys',
        'api_key_updated' => 'API keys updated',
        'disconnected' => 'Stripe disconnected',
    ],

    'onboarding' => [
        'returned' => 'Welcome back. The status below is what Stripe reports right now; it can take a few minutes to update.',
        'sync_failed' => 'We could not read your account from Stripe. Use "Refresh status" in a moment.',
        'confirm_to_continue' => 'The Stripe link expired. Use "Continue onboarding" to get a new one.',
    ],

    'mail' => [
        'footer' => 'If you did not expect this change, review your team access and your Stripe account.',
        'connected' => [
            'subject' => 'Stripe connected (:mode)',
            'line' => 'Your Stripe account is connected in :mode and can receive payments.',
        ],
        'disconnected' => [
            'subject' => 'Stripe disconnected (:mode)',
            'line' => 'Your Stripe account was disconnected in :mode. Payments stop until you connect again.',
        ],
        'restricted' => [
            'subject' => 'Stripe paused payments (:mode)',
            'line' => 'Stripe paused payments on your account in :mode. Review the pending requirements in the panel.',
        ],
        'invalid_credentials' => [
            'subject' => 'Your Stripe API keys stopped working (:mode)',
            'line' => 'Stripe rejected your stored API key in :mode. New payment links are blocked until you update your keys.',
        ],
        'excessive_permissions' => [
            'subject' => 'Your Stripe key has more permissions than needed (:mode)',
            'line' => 'The restricted key connected in :mode can do more than the platform needs. We recommend replacing it with a more limited key.',
        ],
    ],

    'admin' => [
        'heading' => 'Payment gateway',
        'empty' => 'No gateway connection yet.',
        'mode' => 'Mode',
        'connected_at' => 'Connected',
        'disconnected_at' => 'Disconnected',
        'last_event' => 'Last Stripe event',
        'last_event_value' => ':relative (:date UTC)',
        'no_events' => 'None received yet',
        'events_health' => 'Stripe events',
        'silent' => 'No events in :days days',
        'silent_help' => 'This connection can charge but Stripe has sent it no event. Check the platform\'s Connect webhook destination for this mode, or, for API keys, the endpoint on the merchant\'s Stripe account. A quiet account can also show this.',
    ],

];
