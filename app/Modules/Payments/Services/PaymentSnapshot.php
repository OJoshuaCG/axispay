<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Time\IsoDateTime;

/**
 * The payment as an outgoing event carries it (ADR-0051): frozen when the
 * event is recorded, so Phase 5 delivers what was true at that moment. Our
 * identifiers only, amounts as a decimal string plus minor units, and a
 * generic failure code: the gateway's raw decline code stays internal (it
 * can reveal fraud signals such as a lost or stolen card).
 */
final class PaymentSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PaymentAttempt $attempt, string $linkPrefixedId): array
    {
        $money = $attempt->money();
        $failure = self::genericFailureCode($attempt->last_failure_code, $attempt->last_decline_code);

        return [
            'id' => $attempt->prefixedId(),
            'object' => 'payment',
            'livemode' => $attempt->livemode,
            'payment_link' => $linkPrefixedId,
            'status' => $attempt->status->value,
            'amount' => $money->toDecimalString(),
            'amount_minor' => $money->minorAmount,
            'currency' => $money->currency->value,
            'late_payment' => $attempt->late_payment,
            'failure_count' => $attempt->failure_count,
            'failure' => $failure !== null ? ['code' => $failure] : null,
            'created_at' => $attempt->created_at !== null ? IsoDateTime::format($attempt->created_at) : null,
        ];
    }

    /**
     * Plan 15.2: `payment.failed` carries a generic code. Anything not listed
     * (fraud, lost or stolen card, issuer-specific codes) is `card_declined`.
     */
    public static function genericFailureCode(?string $code, ?string $declineCode): ?string
    {
        if ($code === null && $declineCode === null) {
            return null;
        }

        // The decline code is the more specific of the two.
        foreach ([$declineCode, $code] as $c) {
            $generic = match ($c) {
                'insufficient_funds' => 'insufficient_funds',
                'expired_card' => 'expired_card',
                'incorrect_cvc', 'invalid_cvc', 'incorrect_number', 'invalid_number', 'invalid_expiry_month', 'invalid_expiry_year', 'incorrect_zip' => 'incorrect_card_details',
                'payment_intent_authentication_failure', 'authentication_required' => 'authentication_failed',
                'processing_error' => 'processing_error',
                default => null,
            };

            if ($generic !== null) {
                return $generic;
            }
        }

        return 'card_declined';
    }
}
