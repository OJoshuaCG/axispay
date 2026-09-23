<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Product identity (ADR-028)
    |--------------------------------------------------------------------------
    |
    | "PayLink" is the working name used in code, prefixes and namespaces. The
    | display name shown to people comes from APP_NAME. Final naming is open
    | question #10 of the master plan.
    |
    */

    'product_code' => 'paylink',

    'api_key_prefix' => 'plk',

    /*
    |--------------------------------------------------------------------------
    | Surface hosts (ADR-027, plan section 4.1)
    |--------------------------------------------------------------------------
    */

    'surfaces' => [
        'admin' => env('PAYLINK_ADMIN_HOST', 'admin.localhost'),
        'app' => env('PAYLINK_APP_HOST', 'app.localhost'),
        'pay' => env('PAYLINK_PAY_HOST', 'pay.localhost'),
        'api' => env('PAYLINK_API_HOST', 'api.localhost'),
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

];
