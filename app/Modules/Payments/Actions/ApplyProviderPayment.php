<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Http\Presenters\PaymentLinkPresenter;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Data\AppliedPayment;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ReviewReason;
use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Events\PaymentDeclined;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\FlagAttemptForReview;
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
 *     `payment.processing`, and a payment under way (3D Secure step,
 *     authorization) that the gateway reports canceled records
 *     `payment.canceled` with the reason (frozen snapshots, ADR-0051,
 *     ADR-0062);
 *  3. the link follows (plan 9.1): a payment under way → `processing`; the
 *     attempt back to waiting or closed → `active` (or `expired` past its
 *     expiry); succeeded → `paid`. A success on an expired or canceled link
 *     wins: `late_payment` is set on the attempt, the anomaly is audited and
 *     logged, and `payment.succeeded` / `payment_link.paid` carry the flag;
 *  4. an authorization released (`requires_capture` → `canceled`) after a
 *     merchant rejection with `cancel_link` cancels the link
 *     (`rejected_by_merchant`, plan 15.8.4, ADR-0058), whoever released it:
 *     the checkout, a webhook, the reconciliation. It happens under the
 *     same locks as the release, so no newer payment can start in between.
 *
 * Idempotent: applying the same gateway state twice changes nothing
 * (duplicate and out-of-order events, plan 26.2 cases 3 and 4).
 */
final readonly class ApplyProviderPayment
{
    public function __construct(
        private PaymentAttemptStateMachine $attempts,
        private PaymentLinkStateMachine $links,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private FlagAttemptForReview $review,
        private CancelPaymentLink $cancelLink,
    ) {}

    /**
     * @param  string|null  $leaseToken  given by the holder of the attempt's lease, answering its
     *                                   own call to the gateway: only then may the payment move
     *                                   backwards (PaymentAttemptStateMachine::isBackward())
     * @param  VoidReason|null  $voidReason  why we released the payment, when this application is the
     *                                       answer to our own void; null when the gateway reports a
     *                                       cancellation we did not ask for (`gateway_canceled`)
     */
    public function handle(string $attemptId, ProviderPayment $payment, ?string $clientIp = null, ?string $leaseToken = null, ?VoidReason $voidReason = null): AppliedPayment
    {
        $linkId = AttemptLocks::linkIdOf($attemptId);

        $result = DB::transaction(function () use ($linkId, $attemptId, $payment, $clientIp, $leaseToken, $voidReason): AppliedPayment {
            [$link, $attempt] = AttemptLocks::lockLinkThenAttemptOrFail($linkId, $attemptId);

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
                ->where('provider_reference', substr($payment->failure->reference, 0, PaymentAttemptFailure::PROVIDER_REFERENCE_MAX))
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
                    'payment' => PaymentSnapshot::of($attempt, $link),
                    'failure_count' => $attempt->failure_count,
                    'failure_code' => PaymentSnapshot::genericFailureCode($attempt),
                ]);
            }

            if ($target === PaymentAttemptStatus::Processing && $previous !== PaymentAttemptStatus::Processing) {
                $this->events->record(DomainEventType::PaymentProcessing, 'payment', $attempt->id, [
                    'payment' => PaymentSnapshot::of($attempt, $link),
                ]);
            }

            // The integrator may have approved (or credited) this payment in its
            // pre-payment validation: it must learn that the money will not be
            // taken, whoever released it (our void, a refused capture, a webhook).
            if ($payment->status === ProviderPaymentStatus::Canceled && $previous->isInFlight() && $attempt->status->isTerminal()) {
                $this->events->record(DomainEventType::PaymentCanceled, 'payment', $attempt->id, [
                    'payment' => PaymentSnapshot::of($attempt, $link),
                    'reason' => ($voidReason ?? VoidReason::GatewayCanceled)->value,
                ]);
            }

            $this->followLink($link, $attempt, $newDecline);

            if ($previous === PaymentAttemptStatus::RequiresCapture
                && $attempt->status === PaymentAttemptStatus::Canceled
                && $attempt->validation_outcome === ValidationOutcome::Rejected
                && $attempt->validation_cancel_link) {
                $link = $this->cancelRejectedLink($link, $attempt);
            }

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
            'card_brand' => $card?->brand !== null ? substr($card->brand, 0, PaymentAttempt::CARD_BRAND_MAX) : null,
            'card_last4' => $card?->last4 !== null ? substr($card->last4, 0, 4) : null,
            'card_fingerprint' => $card?->fingerprint !== null ? substr($card->fingerprint, 0, PaymentAttempt::CARD_FINGERPRINT_MAX) : null,
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
                    'provider_reference' => substr($failure->reference, 0, PaymentAttemptFailure::PROVIDER_REFERENCE_MAX),
                    'code' => $failure->code !== null ? substr($failure->code, 0, PaymentAttempt::CODE_MAX) : null,
                    'decline_code' => $failure->declineCode !== null ? substr($failure->declineCode, 0, PaymentAttempt::CODE_MAX) : null,
                    'kind' => $failure->kind,
                    'message' => $failure->message,
                    'card_country' => $attempt->card_country,
                    'card_brand' => $attempt->card_brand,
                    'card_fingerprint' => $attempt->card_fingerprint,
                    'client_ip' => $clientIp ?? $attempt->client_ip,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            return false; // already recorded (webhook + checkout + reconciliation)
        }

        $attempt->forceFill([
            'failure_count' => $attempt->failure_count + 1,
            'last_failure_code' => $failure->code !== null ? substr($failure->code, 0, PaymentAttempt::CODE_MAX) : null,
            'last_decline_code' => $failure->declineCode !== null ? substr($failure->declineCode, 0, PaymentAttempt::CODE_MAX) : null,
            'last_failure_kind' => $failure->kind,
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

    /**
     * Plan 15.8.4: the merchant rejected this payment and asked to cancel the
     * link. Called under the link's and the attempt's locks, right after the
     * authorization was released. Only while this attempt is still the
     * link's latest one; a link no longer open for payment (expired, paid)
     * is left as it is.
     */
    private function cancelRejectedLink(PaymentLink $link, PaymentAttempt $attempt): PaymentLink
    {
        $newer = PaymentAttempt::query()
            ->where('payment_link_id', $link->id)
            ->where('id', '>', $attempt->id)
            ->exists();

        if ($newer) {
            return $link;
        }

        try {
            return $this->cancelLink->handle($link, CancelPaymentLinkData::because(CancelReason::RejectedByMerchant), Actor::system());
        } catch (LinkNotCancelableException $e) {
            Log::info('The merchant asked to cancel a link that can no longer be canceled.', ['payment_link_id' => $link->id, 'exception' => $e::class]);

            return $link->refresh();
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
            'payment' => PaymentSnapshot::of($attempt, $link),
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

        $this->review->handle($attempt, ReviewReason::SucceededAfterClose);
    }
}
