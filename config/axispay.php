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

    'impersonation' => [
        // Plan 17.4: impersonation sessions last at most 30 minutes.
        'max_minutes' => 30,
        // Lifetime of the single-use hand-off link from the admin host to the app host.
        'handoff_seconds' => 120,
    ],

];
