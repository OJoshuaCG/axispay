<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Events\PaymentLinkClosed;
use App\Modules\PaymentLinks\Exceptions\InvalidStateTransition;
use App\Modules\PaymentLinks\Http\Presenters\PaymentLinkPresenter;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Services\DomainEventRecorder;
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
 *
 * Expiring and canceling record `payment_link.expired` / `payment_link.canceled`
 * here, in the caller's transaction (outbox rule, rules.md rule 7): every path
 * that closes a link (the expiry job, the API, the panel, a disconnected
 * gateway, a closed tenant, a merchant rejection, an attempt that ends after
 * the expiry) goes through these two methods, so none can skip the event
 * (ADR-0067).
 */
final class PaymentLinkStateMachine
{
    public function __construct(private readonly DomainEventRecorder $events) {}

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
        $expired = $this->apply($locked, PaymentLinkStatus::Expired, ['expired_at' => CarbonImmutable::now()]);
        $this->recordClosed(DomainEventType::PaymentLinkExpired, $expired);

        return $expired;
    }

    public function cancel(PaymentLink $locked, ?string $reason): PaymentLink
    {
        $canceled = $this->apply($locked, PaymentLinkStatus::Canceled, [
            'canceled_at' => CarbonImmutable::now(),
            'cancel_reason' => $reason,
        ]);
        $this->recordClosed(DomainEventType::PaymentLinkCanceled, $canceled);

        return $canceled;
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

    /**
     * The link object as the API shows it right after the transition; the
     * cancellation reason travels in it (`cancel_reason`).
     */
    private function recordClosed(DomainEventType $type, PaymentLink $closed): void
    {
        $this->events->record($type, 'payment_link', $closed->id, [
            'payment_link' => PaymentLinkPresenter::toApi($closed),
        ]);
    }
}
