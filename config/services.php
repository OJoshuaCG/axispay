<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Stripe (plan 12.2). Secrets per mode, never mixed (rules.md rule 4). The
    | API version is pinned for every client and every webhook endpoint we
    | create; it matches the stripe-php SDK's own version (ADR-0047).
    | Changing it is a deliberate, staged change.
    */
    'stripe' => [
        'api_version' => '2026-08-26.dahlia',
        'test' => [
            'secret' => env('STRIPE_TEST_SECRET'),
            'publishable' => env('STRIPE_TEST_PUBLISHABLE'),
            'connect_webhook_secret' => env('STRIPE_TEST_CONNECT_WEBHOOK_SECRET'),
            'connect_client_id' => env('STRIPE_TEST_CONNECT_CLIENT_ID'),
        ],
        'live' => [
            'secret' => env('STRIPE_LIVE_SECRET'),
            'publishable' => env('STRIPE_LIVE_PUBLISHABLE'),
            'connect_webhook_secret' => env('STRIPE_LIVE_CONNECT_WEBHOOK_SECRET'),
            'connect_client_id' => env('STRIPE_LIVE_CONNECT_CLIENT_ID'),
        ],
        // Automatic retries of network failures; stripe-php reuses the same
        // Idempotency-Key on every retry (rules.md rule 5).
        'max_network_retries' => 2,
    ],

    /*
    | Cloudflare Turnstile (plan 11.7 rule 3): the checkout's bot check after a
    | decline, verified on the server before calling the gateway. Cloudflare's
    | documented test keys work in local and testing (docs/development.md).
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'timeout_seconds' => 5,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
