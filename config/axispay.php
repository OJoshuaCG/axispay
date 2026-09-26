<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Product identity (ADR-0037)
    |--------------------------------------------------------------------------
    |
    | "axispay" is the internal name used in code, config keys, prefixes and
    | infrastructure. It never changes with branding.
    |
    | display_name is the public name shown to people (layouts, panels, 2FA
    | issuer, mail sender). To rebrand, change AXISPAY_DISPLAY_NAME only; do
    | not change APP_NAME, which drives cache, Redis and session prefixes.
    |
    */

    'product_code' => 'axispay',

    'display_name' => env('AXISPAY_DISPLAY_NAME', 'AxisPay'),

    'api_key_prefix' => 'axp',

    /*
    |--------------------------------------------------------------------------
    | Surface hosts (ADR-027, plan section 4.1)
    |--------------------------------------------------------------------------
    */

    'surfaces' => [
        'admin' => env('AXISPAY_ADMIN_HOST', 'admin.localhost'),
        'app' => env('AXISPAY_APP_HOST', 'app.localhost'),
        'pay' => env('AXISPAY_PAY_HOST', 'pay.localhost'),
        'api' => env('AXISPAY_API_HOST', 'api.localhost'),
    ],

    /*
    | One session cookie per panel host (ADR-0034). Cookies stay host-only:
    | `session.domain` must be null (checked at boot).
    */
    'session_cookies' => [
        'admin' => 'axispay_admin_session',
        'app' => 'axispay_app_session',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported currencies (plan section 8.1)
    |--------------------------------------------------------------------------
    |
    | Limits are in minor units. The minimums are placeholders based on the
    | historical Stripe references and the maximums are platform risk limits;
    | both remain to be confirmed (open question #6) before Phase 3.
    |
    */

    'currencies' => [
        'USD' => [
            'enabled' => true,
            'min_charge_minor' => 50,
            'max_charge_minor' => 1_000_000,
        ],
        'MXN' => [
            'enabled' => true,
            'min_charge_minor' => 1_000,
            'max_charge_minor' => 20_000_000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform limits (plan section 7.3; not configurable by tenants)
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_expiration_hours' => 2160,
        'min_expiration_minutes' => 15,
        'max_fx_markup_bps' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity and access (plan 17.3, 17.4)
    |--------------------------------------------------------------------------
    |
    | Re-authentication for sensitive actions uses auth.password_timeout
    | (600 seconds, plan 17.3).
    |
    */

    'invitations' => [
        // Plan 17.3: single-use token, 72-hour expiry.
        'expires_hours' => 72,
        // Per-tenant throttle (ADR-0034): limits e-mail enumeration through refusals.
        'max_per_hour' => 20,
    ],

    'passwords' => [
        // Plan 17.3: at least 12 characters and not found in known breaches.
        'min_length' => 12,
        'check_uncompromised' => (bool) env('AXISPAY_PASSWORD_CHECK_UNCOMPROMISED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways (plan 12, ADR-004, ADR-0047)
    |--------------------------------------------------------------------------
    |
    | Keys and secrets live in config/services.php (`stripe`). This block is
    | product behaviour: which connection methods the tenant panel offers, the
    | countries a connected account may be in, the Connect controller
    | properties and what an api_key connection must be allowed to do.
    |
    */

    'gateways' => [
        'stripe' => [
            // Offered in this order in the panel (plan 12.3). `oauth` stays off
            // until Phase 4B confirms it is available to the platform.
            'connection_methods' => [
                'platform_onboarding' => (bool) env('AXISPAY_STRIPE_PLATFORM_ONBOARDING', true),
                'oauth' => false,
                'api_key' => (bool) env('AXISPAY_STRIPE_API_KEY_CONNECTIONS', true),
            ],

            // ISO-3166 alpha-2 countries a connected account may be in. The
            // platform account is in MX (ADR-0047). The first entry is the
            // default country of new platform_onboarding accounts.
            'allowed_countries' => array_values(array_filter(array_map(
                static fn (string $country): string => strtoupper(trim($country)),
                explode(',', (string) env('AXISPAY_STRIPE_ALLOWED_COUNTRIES', 'MX')),
            ), static fn (string $country): bool => preg_match('/^[A-Z]{2}$/', $country) === 1)),

            // Accounts API controller properties equivalent to a Standard
            // account (plan 12.3.1): the merchant pays Stripe's fees, Stripe
            // (not the platform) is liable for negative balances, Stripe
            // collects the requirements and the merchant gets the full
            // Stripe Dashboard. Verified against the Stripe docs (ADR-0047).
            'account_controller' => [
                'fees' => ['payer' => 'account'],
                'losses' => ['payments' => 'stripe'],
                'requirement_collection' => 'stripe',
                'stripe_dashboard' => ['type' => 'full'],
            ],

            // Events of the webhook endpoint created on a merchant account
            // (api_key method, plan 14.3). ONLY the events handled by the
            // current phase: a merchant account also carries sales the
            // platform never made, and we do not want their payer data
            // (ADR-0047). Phase 4 adds the payment_intent.*, charge.refunded,
            // refund.* and charge.dispute.* events here, then runs
            // `axispay:stripe-sync-webhook-endpoints` to update the existing
            // endpoints. Account deauthorization does not exist on a direct
            // endpoint: revocation is detected by authentication errors.
            'direct_webhook_events' => [
                'account.updated',
            ],

            // Incoming events (plan 14.4): rows that were ignored (events we do
            // not handle, foreign objects) or unroutable are deleted after
            // this many days; processed and failed rows keep only a reduced
            // payload after `processed_payload_days`.
            'provider_events' => [
                'ignored_retention_days' => 7,
                'processed_payload_days' => 30,
            ],

            // Version of the risk notice the tenant accepts before storing API
            // keys (lang gateways.api_key.risk). Bump it when the text changes;
            // the accepted version is recorded in the audit log.
            'api_key_risk_notice_version' => 1,

            // Seconds a signed webhook stays valid (Stripe's default).
            'webhook_tolerance' => 300,

            // Base URL of the endpoints registered on merchant accounts
            // (api_key). Empty: https://<API host>. Local development needs a
            // public tunnel URL here, because Stripe cannot reach *.localhost.
            'webhook_base_url' => env('AXISPAY_STRIPE_WEBHOOK_BASE_URL'),
        ],
    ],

    /*
    | Encryption of merchant credentials (api_key method, plan 12.3.3, 23.2).
    | A dedicated key, never APP_KEY, backed up separately from APP_KEY and
    | from database backups. `key_version` tags every ciphertext; previous
    | versions stay readable until `axispay:rotate-gateway-credentials-key`
    | has re-encrypted everything.
    |
    |   GATEWAY_CREDENTIALS_KEY=base64:<32 random bytes>
    |   GATEWAY_CREDENTIALS_KEY_VERSION=2
    |   GATEWAY_CREDENTIALS_PREVIOUS_KEYS=1:base64:<old key>
    */
    'gateway_credentials' => [
        'key' => env('GATEWAY_CREDENTIALS_KEY'),
        'key_version' => (int) env('GATEWAY_CREDENTIALS_KEY_VERSION', 1),
        'previous_keys' => env('GATEWAY_CREDENTIALS_PREVIOUS_KEYS', ''),
    ],

    'impersonation' => [
        // Plan 17.4: impersonation sessions last at most 30 minutes.
        'max_minutes' => 30,
        // Lifetime of the single-use hand-off link from the admin host to the app host.
        'handoff_seconds' => 120,
    ],

];
