<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\InvalidStateTransition;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The link state machine of plan 9.1 (rules.md rule 8). The table holds every
 * transition of the plan; Phase 3 applies the ones it owns (active → expired,
 * active → canceled). Transitions driven by payment attempts (into or out of
 * `processing`, into `paid`) are defined here but refused until Phase 4 wires
 * them to the attempt state machine.
 *
 * Callers pass a row they locked with lockForUpdate() inside a transaction;
 * apply() refuses to run outside one. An invalid transition is logged and
 * throws InvalidStateTransition.
 */
final class PaymentLinkStateMachine
{
    /** @var array<string, list<PaymentLinkStatus>> */
    private const array TRANSITIONS = [
        'active' => [PaymentLinkStatus::Processing, PaymentLinkStatus::Paid, PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled],
        'processing' => [PaymentLinkStatus::Active, PaymentLinkStatus::Paid, PaymentLinkStatus::Expired],
        // Plan 9.1 / ADR-006: a late successful payment wins.
        'expired' => [PaymentLinkStatus::Paid],
        'canceled' => [PaymentLinkStatus::Paid],
        'paid' => [],
    ];

    public static function canTransition(PaymentLinkStatus $from, PaymentLinkStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /** Attempt-driven transitions (Phase 4). */
    private static function isAttemptDriven(PaymentLinkStatus $from, PaymentLinkStatus $to): bool
    {
        return $to === PaymentLinkStatus::Processing
            || $to === PaymentLinkStatus::Paid
            || $from === PaymentLinkStatus::Processing;
    }

    public function expire(PaymentLink $locked): PaymentLink
    {
        return $this->apply($locked, PaymentLinkStatus::Expired, ['expired_at' => CarbonImmutable::now()]);
    }

    public function cancel(PaymentLink $locked, ?string $reason): PaymentLink
    {
        return $this->apply($locked, PaymentLinkStatus::Canceled, [
            'canceled_at' => CarbonImmutable::now(),
            'cancel_reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  columns written with the new status
     */
    private function apply(PaymentLink $locked, PaymentLinkStatus $to, array $attributes): PaymentLink
    {
        $from = $locked->status;

        if (DB::transactionLevel() === 0) {
            throw new InvalidStateTransition($from, $to, 'must run inside a transaction on a locked row');
        }

        if (! self::canTransition($from, $to)) {
            Log::warning('Refused payment link transition.', ['payment_link_id' => $locked->id, 'from' => $from->value, 'to' => $to->value]);

            throw new InvalidStateTransition($from, $to);
        }

        if (self::isAttemptDriven($from, $to)) {
            throw new InvalidStateTransition($from, $to, 'is driven by payment attempts (Phase 4)');
        }

        $locked->forceFill(['status' => $to, ...$attributes])->save();

        return $locked;
    }
}
