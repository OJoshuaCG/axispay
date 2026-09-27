<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Http\Presenters\PaymentLinkPresenter;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Data\AppliedPayment;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Events\PaymentDeclined;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\PaymentAttemptStateMachine;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * THE single place where a gateway payment, as the gateway reports it now,
 * is applied to our attempt and its link (plan 9, 12.5, 14.2 step 7). The
 * checkout's synchronous path, the webhook handler and the reconciliation
 * all come here, so there is no duplicated logic (plan 12.5).
 *
 * In one transaction, locking the link first and then the attempt (the same
 * order everywhere, rules.md rule 8):
 *
 *  1. a new decline is recorded once (keyed by the gateway's reference) and
 *     `failure_count` grows (plan 9.2);
 *  2. the attempt moves to the status of the gateway payment; a new decline
 *     records `payment.failed`, entering `processing` records
 *     `payment.processing` (frozen snapshots, ADR-0051);
 *  3. the link follows (plan 9.1): a payment under way → `processing`; the
 *     attempt back to waiting or closed → `active` (or `expired` past its
 *     expiry); succeeded → `paid`. A success on an expired or canceled link
 *     wins: `late_payment` is set on the attempt, the anomaly is audited and
 *     logged, and `payment.succeeded` / `payment_link.paid` carry the flag.
 *
 * Idempotent: applying the same gateway state twice changes nothing
 * (duplicate and out-of-order events, plan 26.2 cases 3 and 4).
 */
final readonly class ApplyProviderPayment
{
    public const string SUCCEEDED_AFTER_CLOSE = 'succeeded_after_close';

    public function __construct(
        private PaymentAttemptStateMachine $attempts,
        private PaymentLinkStateMachine $links,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $leaseToken  given by the holder of the attempt's lease, answering its
     *                                   own call to the gateway: only then may the payment move
     *                                   backwards (PaymentAttemptStateMachine::isBackward())
     */
    public function handle(string $attemptId, ProviderPayment $payment, ?string $clientIp = null, ?string $leaseToken = null): AppliedPayment
    {
        $result = DB::transaction(function () use ($attemptId, $payment, $clientIp, $leaseToken): AppliedPayment {
            $linkId = PaymentAttempt::query()->whereKey($attemptId)->value('payment_link_id');
            $link = PaymentLink::query()->whereKey(is_string($linkId) ? $linkId : '')->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if ($attempt->provider_payment_id !== null && $attempt->provider_payment_id !== $payment->providerPaymentId) {
                throw new LogicException('The gateway payment does not belong to this attempt.');
            }

            if ($attempt->status->isTerminal()) {
                if ($attempt->status !== PaymentAttemptStatus::Succeeded && $payment->status->value === PaymentAttemptStatus::Succeeded->value) {
                    $this->succeededAfterClose($attempt);
                }

                return new AppliedPayment($attempt, $link, false);
            }

            if ($payment->amountMinor !== $attempt->amount_minor || strtoupper($payment->currency) !== $attempt->currency->value) {
                // Never adopt a gateway payment for another amount (a bug or a
                // tampered object): nothing is applied, the anomaly is loud.
                Log::critical('A gateway payment does not match its attempt amount; not applied.', ['payment_attempt_id' => $attempt->id]);

                return new AppliedPayment($attempt, $link, false);
            }

            $target = PaymentAttemptStatus::fromProvider($payment->status, $attempt->failure_count + ($payment->failure !== null ? 1 : 0));

            $freshDecline = $payment->failure !== null && ! PaymentAttemptFailure::query()
                ->where('payment_attempt_id', $attempt->id)
                ->where('provider_reference', substr($payment->failure->reference, 0, 255))
                ->exists();
            $stale = PaymentAttemptStateMachine::isBackward($attempt->status, $target)
                || (PaymentAttemptStateMachine::needsFreshDecline($attempt->status, $target) && ! $freshDecline);

            if ($stale && ! AttemptLease::holds($attempt, $leaseToken)) {
                Log::notice('A stale gateway read was not applied.', ['payment_attempt_id' => $attempt->id, 'from' => $attempt->status->value, 'to' => $target->value]);

                return new AppliedPayment($attempt, $link, false);
            }

            $this->recordCard($attempt, $payment);
            $newDecline = $payment->failure !== null && $this->recordDecline($attempt, $payment->failure, $clientIp);

            $target = PaymentAttemptStatus::fromProvider($payment->status, $attempt->failure_count);
            $previous = $attempt->status;

            if ($target !== $previous) {
                $this->attempts->transition($attempt, $target);
            }

            // Recorded after the transition, so the frozen snapshot shows the
            // payment as it is now (ADR-0051).
            if ($newDecline) {
                $this->events->record(DomainEventType::PaymentFailed, 'payment', $attempt->id, [
                    'payment' => PaymentSnapshot::of($attempt, $link->prefixedId()),
                    'failure_count' => $attempt->failure_count,
                    'failure_code' => PaymentSnapshot::genericFailureCode($attempt->last_failure_code, $attempt->last_decline_code),
                ]);
            }

            if ($target === PaymentAttemptStatus::Processing && $previous !== PaymentAttemptStatus::Processing) {
                $this->events->record(DomainEventType::PaymentProcessing, 'payment', $attempt->id, [
                    'payment' => PaymentSnapshot::of($attempt, $link->prefixedId()),
                ]);
            }

            $this->followLink($link, $attempt, $newDecline);

            return new AppliedPayment($attempt, $link, $newDecline);
        });

        if ($result->newDecline) {
            event(new PaymentDeclined($result->attempt->tenant_id, $result->attempt->livemode, $result->link->id, $result->attempt->id));
        }

        return $result;
    }

    private function recordCard(PaymentAttempt $attempt, ProviderPayment $payment): void
    {
        $card = $payment->cardPreview;

        $attempt->forceFill(array_filter([
            'provider_payment_id' => $attempt->provider_payment_id ?? $payment->providerPaymentId,
            'card_country' => $card?->country !== null ? strtoupper(substr($card->country, 0, 2)) : null,
            'card_brand' => $card?->brand !== null ? substr($card->brand, 0, 32) : null,
            'card_last4' => $card?->last4 !== null ? substr($card->last4, 0, 4) : null,
            'capture_before' => $payment->captureBefore !== null ? CarbonImmutable::parse($payment->captureBefore)->utc() : null,
        ], static fn (mixed $value): bool => $value !== null));

        if ($attempt->isDirty()) {
            $attempt->save();
        }
    }

    private function recordDecline(PaymentAttempt $attempt, ProviderPaymentFailure $failure, ?string $clientIp): bool
    {
        try {
            DB::transaction(function () use ($attempt, $failure, $clientIp): void {
                $row = new PaymentAttemptFailure;
                $row->forceFill([
                    'payment_attempt_id' => $attempt->id,
                    'provider_reference' => substr($failure->reference, 0, 255),
                    'code' => $failure->code !== null ? substr($failure->code, 0, 64) : null,
                    'decline_code' => $failure->declineCode !== null ? substr($failure->declineCode, 0, 64) : null,
                    'message' => $failure->message,
                    'card_country' => $attempt->card_country,
                    'card_brand' => $attempt->card_brand,
                    'client_ip' => $clientIp ?? $attempt->client_ip,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            return false; // already recorded (webhook + checkout + reconciliation)
        }

        $attempt->forceFill([
            'failure_count' => $attempt->failure_count + 1,
            'last_failure_code' => $failure->code !== null ? substr($failure->code, 0, 64) : null,
            'last_decline_code' => $failure->declineCode !== null ? substr($failure->declineCode, 0, 64) : null,
            'last_failure_message' => $failure->message,
        ])->save();

        return true;
    }

    private function followLink(PaymentLink $link, PaymentAttempt $attempt, bool $newDecline): void
    {
        $status = $attempt->status;

        if ($status === PaymentAttemptStatus::Succeeded) {
            if ($link->status !== PaymentLinkStatus::Paid) {
                $this->paid($link, $attempt);
            }

            return;
        }

        if ($status->isInFlight()) {
            if ($link->status === PaymentLinkStatus::Active) {
                $this->links->enterProcessing($link);
            }

            return;
        }

        // Waiting for a payment method again, or closed without success. While
        // a confirmation holds the lease the link stays reserved; the holder
        // frees it (ReleaseLinkAfterAttempt) unless this is its decline.
        if ($link->status === PaymentLinkStatus::Processing && ($newDecline || $attempt->status->isTerminal() || ! $attempt->leaseHeld())) {
            $this->links->resumeAfterAttempt($link);
        }
    }

    private function paid(PaymentLink $link, PaymentAttempt $attempt): void
    {
        $previous = $link->status;
        $late = $this->links->markPaid($link);

        if ($late) {
            $attempt->forceFill(['late_payment' => true])->save();

            $this->audit->record(AuditAction::PaymentLateSucceeded, $attempt, [
                'payment_link_id' => $link->id,
                'link_status_before' => $previous->value,
                'livemode' => $link->livemode,
            ], actor: Actor::system());

            Log::warning('A payment succeeded after its link was closed; the payment wins.', [
                'payment_attempt_id' => $attempt->id,
                'payment_link_id' => $link->id,
                'link_status_before' => $previous->value,
            ]);
        }

        // Frozen snapshots of both objects (ADR-0051, plan 15.3).
        $facts = [
            'payment' => PaymentSnapshot::of($attempt, $link->prefixedId()),
            'payment_link' => PaymentLinkPresenter::toApi($link),
            'late_payment' => $late,
        ];

        $this->events->record(DomainEventType::PaymentSucceeded, 'payment', $attempt->id, $facts);
        $this->events->record(DomainEventType::PaymentLinkPaid, 'payment_link', $link->id, $facts);
    }

    /**
     * A closed attempt whose payment succeeded at the gateway anyway (a void
     * that lost a race with a capture, or a gateway bug): money may be
     * unaccounted for. The attempt is not reopened; it is flagged for review,
     * audited and logged at critical level (ADR-0051).
     */
    private function succeededAfterClose(PaymentAttempt $attempt): void
    {
        Log::critical('A closed payment attempt succeeded at the gateway.', ['payment_attempt_id' => $attempt->id, 'status' => $attempt->status->value]);

        if ($attempt->needs_review) {
            return;
        }

        $attempt->forceFill(['needs_review' => true, 'review_reason' => self::SUCCEEDED_AFTER_CLOSE])->save();

        $this->audit->record(AuditAction::PaymentNeedsReview, $attempt, [
            'reason' => self::SUCCEEDED_AFTER_CLOSE,
            'status' => $attempt->status->value,
            'livemode' => $attempt->livemode,
        ], actor: Actor::system());
    }
}
