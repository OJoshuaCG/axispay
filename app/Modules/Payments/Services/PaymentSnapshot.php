<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Gateways\Enums\ProviderFailureKind;
use App\Modules\PaymentLinks\Enums\DisputeStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\IsoDateTime;

/**
 * The payment as an outgoing event carries it (ADR-0051): frozen when the
 * event is recorded, so Phase 5 delivers what was true at that moment. Our
 * identifiers only, amounts as a decimal string plus minor units, and a
 * generic failure code: the gateway's raw decline code stays internal (it
 * can reveal fraud signals such as a lost or stolen card).
 *
 * Also the base of the `payment` object of `GET /v1/payments` (the Payment
 * API presenter adds the card and the authorization/cancellation times), so
 * what an integrator reads back always agrees with what the event said.
 */
final class PaymentSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PaymentAttempt $attempt, PaymentLink $link): array
    {
        $money = $attempt->money();
        $failure = self::genericFailureCode($attempt);

        return [
            'id' => $attempt->prefixedId(),
            'object' => 'payment',
            'livemode' => $attempt->livemode,
            'payment_link' => $link->prefixedId(),
            // The merchant's own reference of the link (spec B2): lets the
            // integrator match the event without a lookup.
            'client_reference_id' => $link->client_reference_id,
            'status' => $attempt->status->value,
            'amount' => $money->toDecimalString(),
            'amount_minor' => $money->minorAmount,
            'currency' => $money->currency->value,
            // The conversion applied to this payment (FxBlock: mode, rate, source,
            // rate date, original amount); null when the payment was charged in
            // the link's own currency. The key is always present.
            'fx' => FxBlock::of($attempt),
            'late_payment' => $attempt->late_payment,
            'failure_count' => $attempt->failure_count,
            'failure' => $failure !== null ? ['code' => $failure] : null,
            // Plan 15.8.5: how the merchant's pre-payment validation ended
            // (null when none applied), e.g. a `fail_open` capture.
            'pre_validation' => $attempt->validation_outcome?->publicBlock(),
            // When the payment was captured (it moved to `succeeded`); null until then.
            'captured_at' => $attempt->succeeded_at !== null ? IsoDateTime::format($attempt->succeeded_at) : null,
            // What went back to the payer (plan 10.6, 16): the succeeded refunds, in
            // the currency charged, and the summaries of refunds and disputes.
            // Only a captured payment can be refunded or disputed.
            'amount_refunded' => Money::ofMinor($attempt->amount_refunded_minor, $attempt->currency)->toDecimalString(),
            'amount_refunded_minor' => $attempt->amount_refunded_minor,
            'refund_status' => RefundSummary::of($attempt)->value,
            'dispute_status' => $attempt->status === PaymentAttemptStatus::Succeeded ? $link->dispute_status->value : DisputeStatus::None->value,
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
