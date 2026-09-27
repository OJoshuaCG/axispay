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
        }

        $locked->forceFill($attributes)->save();

        return $locked;
    }
}
