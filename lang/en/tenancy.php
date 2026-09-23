<?php

declare(strict_types=1);

/*
 * Tenancy: tenant statuses, test/live mode, tenant panel banners and e-mails.
 */
return [

    'status' => [
        'pending_onboarding' => 'Pending onboarding',
        'active' => 'Active',
        'grace' => 'Grace period',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ],

    'mode' => [
        'test' => 'Test mode',
        'live' => 'Live mode',
        'test_short' => 'Test',
        'live_short' => 'Live',
        'switch_to_live' => 'Switch to live data',
        'switch_to_test' => 'Switch to test data',
    ],

    'banner' => [
        'pending_onboarding' => 'Your account is being set up. Some features are not available yet.',
        'grace' => 'Your account is in a grace period. Please contact us to keep it active.',
        'suspended' => 'Your account is suspended. You can view your data, but you cannot make changes or create new links.',
    ],

    'mail' => [
        'status_changed' => [
            'subject' => 'Your account status has changed',
            'line' => 'The status of your account is now: :status.',
            'contact' => 'If you have questions, please contact support.',
        ],
    ],

];
