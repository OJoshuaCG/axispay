<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\Fx\Data\ConversionDecision;
use App\Modules\Fx\Enums\ConversionBlockReason;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Data\TenantSettings;

/**
 * When a payment is converted from USD to MXN (plan 13.2, ADR-009):
 *
 *   needs conversion = link in USD AND connected account in Mexico
 *                      AND card issued in Mexico
 *
 * Stripe accounts in Mexico can only charge Mexican cards in MXN, so a USD
 * link cannot be charged to such a card as it is. When the conversion is
 * needed and the tenant has it on and the link did not opt out, the
 * checkout converts (after the payer confirms); otherwise it refuses
 * instead of letting the gateway reject the charge (a rejection would count
 * against the merchant's account). Any other combination charges the link's
 * own currency.
 */
final class ConversionPolicy
{
    private const string MEXICO = 'MX';

    public function decide(PaymentLink $link, ?string $accountCountry, ?string $cardCountry, TenantSettings $settings): ConversionDecision
    {
        $needsConversion = $link->currency === CurrencyCode::USD
            && self::isMexico($accountCountry)
            && self::isMexico($cardCountry);

        if (! $needsConversion) {
            return ConversionDecision::notRequired();
        }

        if (! $settings->fxConversionEnabled || ! $link->fx_mode->converts()) {
            return ConversionDecision::blocked(ConversionBlockReason::ConversionDisabled);
        }

        return ConversionDecision::convert($link->fx_mode);
    }

    private static function isMexico(?string $country): bool
    {
        return $country !== null && strtoupper($country) === self::MEXICO;
    }
}
