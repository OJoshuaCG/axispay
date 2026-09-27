<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Exceptions\InvalidAttemptTransition;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Database\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The attempt state machine of plan 9.2 with the authorized stage of
 * ADR-0050 (rules.md rule 8). Attempts mirror the gateway payment, whose
 * current state is always re-read before applying (ADR-017), so any active
 * status may follow any other: events can be missed or arrive out of order
 * (plan 26.2 case 4). Terminal statuses never change again.
 *
 * Callers pass an attempt they locked with lockForUpdate() inside a
 * transaction. Each final status stamps its time; `authorized_at` is stamped
 * when the authorization is first seen.
 */
final class PaymentAttemptStateMachine
{
    public static function canTransition(PaymentAttemptStatus $from, PaymentAttemptStatus $to): bool
    {
        return ! $from->isTerminal() && $from !== $to;
    }

    /**
     * A move backwards in the payment's progress: an authorized or
     * processing payment reported again as waiting for the bank or for a card.
     * The gateway never does it on its own; from a read it is a stale view
     * (an older read applied after a newer one), so ApplyProviderPayment
     * only accepts it from the lease holder, answering its own call (plan
     * 26.2 case 4).
     */
    public static function isBackward(PaymentAttemptStatus $from, PaymentAttemptStatus $to): bool
    {
        return match ($from) {
            PaymentAttemptStatus::RequiresCapture => in_array($to, [PaymentAttemptStatus::RequiresPaymentMethod, PaymentAttemptStatus::RequiresConfirmation, PaymentAttemptStatus::RequiresAction], true),
            // A processing payment may still fail (back to a card), but never asks the bank again.
            PaymentAttemptStatus::Processing => in_array($to, [PaymentAttemptStatus::RequiresConfirmation, PaymentAttemptStatus::RequiresAction], true),
            default => false,
        };
    }

    /**
     * A payment under way (bank verification, processing) reported as waiting
     * for a card again. Legitimate only as the result of a failure: a read
     * that carries a decline not recorded yet, or the lease holder's own call.
     * Anything else is an older read arriving late (ADR-0051).
     */
    public static function needsFreshDecline(PaymentAttemptStatus $from, PaymentAttemptStatus $to): bool
    {
        return in_array($from, [PaymentAttemptStatus::RequiresAction, PaymentAttemptStatus::Processing], true)
            && in_array($to, [PaymentAttemptStatus::RequiresPaymentMethod, PaymentAttemptStatus::RequiresConfirmation], true);
    }

    public function transition(PaymentAttempt $locked, PaymentAttemptStatus $to): PaymentAttempt
    {
        $from = $locked->status;

        if (! Transactions::open()) {
            throw new InvalidAttemptTransition($from, $to, 'must run inside a transaction on a locked row');
        }

        if (! self::canTransition($from, $to)) {
            Log::warning('Refused payment attempt transition.', ['payment_attempt_id' => $locked->id, 'from' => $from->value, 'to' => $to->value]);

            throw new InvalidAttemptTransition($from, $to);
        }

        $now = CarbonImmutable::now();
        $attributes = ['status' => $to];

        match ($to) {
            PaymentAttemptStatus::RequiresCapture => $attributes['authorized_at'] = $locked->authorized_at ?? $now,
            PaymentAttemptStatus::Succeeded => $attributes['succeeded_at'] = $now,
            PaymentAttemptStatus::Failed => $attributes['failed_at'] = $now,
            PaymentAttemptStatus::Canceled => $attributes['canceled_at'] = $now,
            default => null,
        };

        if ($to->isTerminal()) {
            $attributes['confirmation_lease_until'] = null;
            $attributes['confirmation_lease_token'] = null;
        }

        $locked->forceFill($attributes)->save();

        return $locked;
    }
}
