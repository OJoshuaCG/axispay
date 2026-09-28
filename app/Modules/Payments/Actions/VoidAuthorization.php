<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ReviewReason;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use App\Modules\Payments\Exceptions\CallBudgetExhausted;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\FlagAttemptForReview;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Payments\Services\PaymentAttemptStateMachine;
use App\Modules\Shared\Database\Transactions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Cancels the gateway payment of an attempt that is not captured (ADR-0050):
 * an authorization is voided (no money moves, no refund), an unconfirmed
 * payment is closed. Used by a merchant rejection or `fail_closed` (Phase 5),
 * the reconciliation (authorizations left uncaptured) and the closing of a
 * link that expired or was canceled (plan 9.1). The result is applied like
 * any gateway state: if the payment had already succeeded, the payment wins.
 *
 * Gateway errors propagate (the caller or its job retries with the same key).
 */
final readonly class VoidAuthorization
{
    public function __construct(
        private AttemptGateway $gateways,
        private ApplyProviderPayment $apply,
        private AuditLogger $audit,
        private PaymentAttemptStateMachine $machine,
        private AttemptLease $lease,
        private PaymentLinkStateMachine $links,
        private FlagAttemptForReview $review,
    ) {}

    /**
     * @param  string|null  $leaseToken  the caller's lease; without one the lease is taken here
     * @param  CallBudget|null  $budget  a payer request's time budget (null: background work)
     *
     * @throws AttemptBusyException when another process holds the attempt
     * @throws GatewayException when the gateway could not be reached or refused (retry)
     * @throws CallBudgetExhausted before a call that would not fit in the payer's request
     */
    public function handle(string $attemptId, VoidReason $reason, ?string $leaseToken = null, ?CallBudget $budget = null): PaymentAttempt
    {
        if (Transactions::open()) {
            throw new LogicException('Voiding calls the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);

        if ($attempt->status->isTerminal()) {
            return $attempt;
        }

        $token = $leaseToken ?? $this->lease->acquire($attemptId) ?? throw AttemptBusyException::for($attemptId);

        try {
            return $this->void($attempt->refresh(), $reason, $token, $budget);
        } finally {
            if ($leaseToken === null) {
                $this->lease->release($attemptId, $token);
            }
        }
    }

    private function void(PaymentAttempt $attempt, VoidReason $reason, string $token, ?CallBudget $budget): PaymentAttempt
    {
        if ($attempt->status->isTerminal()) {
            return $attempt;
        }

        $wasAuthorized = $attempt->status === PaymentAttemptStatus::RequiresCapture;

        if ($attempt->provider_payment_id === null) {
            return $this->closeLocally($attempt->id); // never reached the gateway
        }

        // The lease must still be ours right before the call (it may have
        // expired while the caller waited): otherwise another actor decides.
        if (! $this->lease->extend($attempt->id, $token)) {
            throw AttemptBusyException::for($attempt->id);
        }

        if ($budget !== null && ! $budget->affords()) {
            throw CallBudgetExhausted::before('void the authorization');
        }

        [$gateway, $connection] = $this->gateways->for($attempt);

        try {
            $providerPaymentId = $attempt->provider_payment_id;
            $gateways = $this->gateways;
            $payment = $gateways->retryingCall(
                $connection,
                IdempotencyKeys::cancel($attempt->id),
                static fn (string $key): ProviderPayment => $gateway->cancelPayment($connection, $providerPaymentId, $key),
                // Still cancelable: repeat; canceled, captured or processing: take it as it is.
                static fn (): ?ProviderPayment => $gateways->movedOn($gateway, $connection, $providerPaymentId, [
                    ProviderPaymentStatus::RequiresPaymentMethod, ProviderPaymentStatus::RequiresConfirmation, ProviderPaymentStatus::RequiresAction, ProviderPaymentStatus::RequiresCapture,
                ]),
                ['payment_attempt_id' => $attempt->id, 'operation' => 'cancel'],
                $budget,
            );
        } catch (GatewayConfigurationException $e) {
            // The connection lost its credentials (a disconnected api_key). A
            // payment that was never authorized cannot be charged any more and
            // is closed locally; an authorization must be voided at the
            // gateway, so that case stays loud.
            if ($wasAuthorized) {
                throw $e;
            }

            // Closed without the gateway: if a confirmation's answer was ever
            // lost, a hold may remain on the payer's card. Flag it for review.
            return $this->closeLocally($attempt->id, needsReview: true);
        }

        $applied = $this->apply->handle($attempt->id, $payment, leaseToken: $token);

        if ($wasAuthorized && $applied->attempt->status->isTerminal() && $applied->attempt->status !== PaymentAttemptStatus::Succeeded) {
            DB::transaction(fn () => $this->audit->record(AuditAction::PaymentAuthorizationVoided, $applied->attempt, [
                'reason' => $reason->value,
                'payment_link_id' => $applied->attempt->payment_link_id,
                'livemode' => $applied->attempt->livemode,
            ], actor: Actor::system()));
        }

        return $applied->attempt;
    }

    private function closeLocally(string $attemptId, bool $needsReview = false): PaymentAttempt
    {
        $linkId = AttemptLocks::linkIdOf($attemptId);

        return DB::transaction(function () use ($linkId, $attemptId, $needsReview): PaymentAttempt {
            [$link, $locked] = AttemptLocks::lockLinkThenAttempt($linkId, $attemptId);

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(PaymentAttempt::class, [$attemptId]);
            }

            if (! $locked->status->isTerminal()) {
                $this->machine->transition($locked, $locked->failure_count > 0 ? PaymentAttemptStatus::Failed : PaymentAttemptStatus::Canceled);
            }

            if ($needsReview) {
                $this->review->handle($locked, ReviewReason::ClosedWithoutGateway);
                Log::warning('A payment attempt was closed without the gateway; a card hold may remain.', ['payment_attempt_id' => $locked->id]);
            }

            if ($link?->status === PaymentLinkStatus::Processing) {
                $this->links->resumeAfterAttempt($link);
            }

            return $locked;
        });
    }
}
