<?php

declare(strict_types=1);

/*
 * Panel wording of public API error codes (plan 10.4), keyed by code. The
 * API itself answers in English; the panels show these instead.
 */
return [

    'tenant_suspended' => 'Your account is read-only in its current state: payment links cannot be created or canceled here. Existing links keep working.',
    'gateway_not_ready' => 'Connect Stripe for this mode (and finish its requirements) before creating links.',
    'parameter_missing' => 'This field is required.',
    'parameter_invalid' => 'Check this value.',
    'amount_invalid' => 'Enter digits with up to two decimals and a dot, with no thousands separators.',
    'amount_below_minimum' => 'The amount is below the minimum for this currency.',
    'amount_above_maximum' => 'The amount is above the maximum allowed for this currency.',
    'currency_not_supported' => 'This currency is not supported.',
    'expiration_out_of_range' => 'The expiration is outside the allowed range.',
    'link_not_cancelable' => 'This link is paid, expired or canceled and can no longer be canceled.',
    'link_payment_in_progress' => 'A payment is in progress for this link; it cannot be canceled now.',
    'insufficient_scope' => 'You do not have the permission for this.',
    'internal_error' => 'Something went wrong. Try again.',
    'metadata_invalid' => 'The metadata is not valid.',
    'payer_field_invalid' => 'The payer fields are not valid.',
    'return_url_not_allowed' => 'The return URL domain is not allowed for your account.',
    'fx_not_available' => 'Currency conversion is not available.',

];
