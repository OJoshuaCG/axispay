<?php

declare(strict_types=1);

/*
 * Currency conversion (plan 13, ADR-0063): superadmin alerts, the tenant
 * "Payment settings" panel page and the conversion block of the pay page
 * live in their own sections.
 */
return [

    'mail' => [
        'requires_review' => [
            'subject' => 'Banxico FIX held back for review',
            'line' => 'The FIX of :date (:rate MXN per USD) differs too much from the previous one, so it was stored but will not be used for charges.',
            'action' => 'Check the figure at Banxico. Until a normal FIX is stored, conversions use the previous one while it is not stale.',
        ],
        'stale' => [
            'subject' => 'The Banxico FIX is stale',
            'line' => 'The newest usable FIX is from :date (:rate MXN per USD). Conversions with the Banxico FIX are blocked until a fresh one is stored.',
            'action' => 'Check the BANXICO_SIE_TOKEN variable and the scheduled fetch. Links with a fixed rate keep working.',
        ],
    ],

    /*
     * The tenant "Payment settings" page (ADR-0063, ADR-0048).
     */
    'settings' => [
        'navigation_group' => 'Settings',
        'title' => 'Payment settings',
        'subheading' => 'Currency conversion for Mexican cards and the expiration of your payment links.',
        'save' => 'Save settings',
        'saved' => 'Payment settings saved.',

        'fx' => [
            'heading' => 'Currency conversion',
            'description' => 'A card issued in Mexico can only be charged in Mexican pesos. When a USD link is paid with one, the payer sees the amount in MXN and confirms it before being charged.',
            'enabled' => 'Convert USD links paid with Mexican cards',
            'enabled_help' => 'When it is off, a Mexican card cannot pay a USD link: the payer is told you cannot charge that amount in USD, and nothing is charged.',
            'mode' => 'How the exchange rate is set',
            'mode_fixed' => 'Fixed rate',
            'mode_fixed_help' => 'You set the rate yourself. The payer is charged USD amount x rate, rounded to the cent. No markup is applied.',
            'mode_banxico_fix' => 'Banxico FIX',
            'mode_banxico_fix_help' => 'The FIX published by Banxico, plus your markup. If the latest FIX is more than 4 days old, conversions are blocked until a new one arrives.',
            'fixed_rate' => 'Fixed rate (MXN per USD)',
            'fixed_rate_help' => 'Up to 6 decimals, for example 20.000000. Payment links may carry their own rate, which takes precedence.',
            'markup' => 'Markup over the FIX (basis points)',
            'markup_help' => '100 basis points = 1.00 %. From 0 to :max. The payer is always told the markup exists.',
            'quote_validity' => 'How long a quote lasts (minutes)',
            'quote_validity_help' => 'From :min to :max minutes. After that the payer may be asked to confirm again if the amount changed.',
        ],

        'links' => [
            'heading' => 'Link expiration',
            'description' => 'Applies to links created without an expiry. No link can last more than :days days.',
            'default' => 'Default expiration',
            'default_help' => 'Used when a link is created without an expiry. It cannot be longer than the maximum below.',
            'max' => 'Maximum expiration',
            'max_help' => 'The longest a link of yours may last: at most :hours hours (:days days). Links that already exist keep their own expiry.',
            'hours' => 'hours',
        ],

        'errors' => [
            'mode' => 'Choose how the exchange rate is set.',
            'fixed_rate_required' => 'Enter the fixed rate, or choose the Banxico FIX.',
            'fixed_rate_format' => 'Enter a number greater than zero with at most 6 decimals, for example 20.000000.',
            'markup' => 'The markup must be between 0 and :max basis points.',
            'quote_validity' => 'The quote must last between :min and :max minutes.',
            'max_expiration' => 'The maximum must be between :min and :max hours (:days days).',
            'default_expiration' => 'The default must be at least :min hour and no longer than the maximum.',
        ],
    ],

];
