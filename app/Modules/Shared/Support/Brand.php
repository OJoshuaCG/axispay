<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Public product name shown to people (ADR-0037).
 *
 * Reads `axispay.display_name` (env AXISPAY_DISPLAY_NAME). Views, panels, the
 * 2FA issuer and the mail sender use this instead of `app.name`: APP_NAME is
 * a fixed internal value that drives cache, Redis and session prefixes, so it
 * must not change when the product is rebranded.
 *
 * Per-tenant branding (plan section 18) is separate: surfaces that show the
 * merchant's name will resolve it from the tenant, not from here.
 */
final class Brand
{
    public const string DEFAULT_DISPLAY_NAME = 'AxisPay';

    public static function displayName(): string
    {
        $name = config('axispay.display_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : self::DEFAULT_DISPLAY_NAME;
    }
}
