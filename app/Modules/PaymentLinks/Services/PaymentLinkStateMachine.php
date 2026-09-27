<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Events\PaymentLinkClosed;
use App\Modules\PaymentLinks\Exceptions\InvalidStateTransition;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The link state machine of plan 9.1 (rules.md rule 8). Every transition has
 * its own method:
 *
 *  - expire() / cancel(): the job, the API and the panel (Phase 3);
 *  - enterProcessing(), resumeAfterAttempt(), markPaid(): driven by payment
 *    attempts (Phase 4, ADR-0051). markPaid() also accepts an expired or
 *    canceled link: a late successful payment wins (ADR-006) and the caller
 *    records the anomaly.
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

    /** An attempt started a payment (bank verification, authorized, processing). */
    public function enterProcessing(PaymentLink $locked): PaymentLink
    {
        return $this->apply($locked, PaymentLinkStatus::Processing, []);
    }

    /**
     * The attempt in progress failed or was canceled: the link can be paid
     * again, or it expires now if its expiry passed meanwhile (plan 9.1).
     */
    public function resumeAfterAttempt(PaymentLink $locked): PaymentLink
    {
        return $locked->isPastExpiry()
            ? $this->expire($locked)
            : $this->apply($locked, PaymentLinkStatus::Active, []);
    }

    /**
     * An attempt succeeded. Returns true when the payment is late (the link
     * was expired or canceled): the payment wins and the caller records the
     * anomaly (plan 9.1, ADR-006).
     */
    public function markPaid(PaymentLink $locked): bool
    {
        $late = in_array($locked->status, [PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled], true);
        $this->apply($locked, PaymentLinkStatus::Paid, ['paid_at' => CarbonImmutable::now()]);

        return $late;
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

        $locked->forceFill(['status' => $to, ...$attributes])->save();

        if ($to === PaymentLinkStatus::Expired || $to === PaymentLinkStatus::Canceled) {
            event(new PaymentLinkClosed($locked->tenant_id, $locked->livemode, $locked->id));
        }

        return $locked;
    }
}
