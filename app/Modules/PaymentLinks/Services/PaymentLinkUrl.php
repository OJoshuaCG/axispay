<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;

/**
 * Public URL of a link (plan 11.1, ADR-005): `https://<pay host>/l/{token}`,
 * or `axispay.links.public_base_url` when set (local development). The
 * checkout page behind it arrives in Phase 4.
 */
final class PaymentLinkUrl
{
    public static function for(PaymentLink $link): string
    {
        return self::base().'/l/'.$link->public_token;
    }

    public static function base(): string
    {
        $configured = config('axispay.links.public_base_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return 'https://'.config()->string('axispay.surfaces.pay');
    }
}
