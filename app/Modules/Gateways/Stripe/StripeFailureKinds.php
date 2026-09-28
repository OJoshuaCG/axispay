<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Enums\ProviderFailureKind;

/**
 * Maps Stripe's error code and decline code of a failed payment to the
 * provider-neutral ProviderFailureKind (plan 15.2). The decline code is the
 * more specific of the two. Anything not listed (fraud, lost or stolen card,
 * issuer-specific codes) is a plain decline.
 */
final class StripeFailureKinds
{
    public static function of(?string $code, ?string $declineCode): ProviderFailureKind
    {
        foreach ([$declineCode, $code] as $candidate) {
            $kind = match ($candidate) {
                'insufficient_funds' => ProviderFailureKind::InsufficientFunds,
                'expired_card' => ProviderFailureKind::ExpiredCard,
                'incorrect_cvc', 'invalid_cvc', 'incorrect_number', 'invalid_number', 'invalid_expiry_month', 'invalid_expiry_year', 'incorrect_zip' => ProviderFailureKind::IncorrectCardDetails,
                'payment_intent_authentication_failure', 'authentication_required' => ProviderFailureKind::AuthenticationFailed,
                'processing_error' => ProviderFailureKind::ProcessingError,
                default => null,
            };

            if ($kind !== null) {
                return $kind;
            }
        }

        return ProviderFailureKind::CardDeclined;
    }
}
