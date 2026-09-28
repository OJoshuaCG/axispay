<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Provider-neutral kind of a failed payment try (plan 15.2): the adapter
 * maps its own error and decline codes to one of these, so the payments
 * domain never reads gateway codes. Also the generic failure code of the
 * outgoing `payment.failed` event. Anything not listed (fraud, lost or
 * stolen card, issuer-specific reasons) is `card_declined`.
 */
enum ProviderFailureKind: string
{
    case CardDeclined = 'card_declined';
    case InsufficientFunds = 'insufficient_funds';
    case ExpiredCard = 'expired_card';
    case IncorrectCardDetails = 'incorrect_card_details';
    case AuthenticationFailed = 'authentication_failed';
    case ProcessingError = 'processing_error';
}
