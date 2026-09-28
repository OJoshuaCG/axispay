<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Gateways\Enums\ProviderFailureKind;
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
        $failure = self::genericFailureCode($attempt);

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
     * Plan 15.2: `payment.failed` carries a generic code, the provider-neutral
     * kind the gateway adapter mapped (never the raw decline code).
     */
    public static function genericFailureCode(PaymentAttempt $attempt): ?string
    {
        return $attempt->last_failure_kind->value ?? ($attempt->failure_count > 0 ? ProviderFailureKind::CardDeclined->value : null);
    }
}
