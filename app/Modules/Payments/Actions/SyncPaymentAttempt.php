<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Exceptions\CallBudgetExhausted;
use App\Modules\Payments\Jobs\CompleteAuthorizedPaymentJob;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Shared\Database\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Re-reads an attempt's payment from the gateway and applies it (ADR-017:
 * the gateway's current state, never an event payload). Then, if the
 * payment is authorized and waiting for capture (ADR-0050):
 *
 *  - reconciliation, a 3D Secure step left unanswered for
 *    `axispay.checkout.abandon_action_after_minutes` → canceled (the payer
 *    abandoned it; the link is released);
 *  - otherwise → CaptureAuthorizedPayment, which captures within the
 *    capture window and voids after it (CaptureWindow, for every caller alike); (a payer who closed the tab after
 *    3D Secure still gets their payment completed). If another process holds
 *    the attempt, a webhook schedules one more try a minute later.
 */
final readonly class SyncPaymentAttempt
{
    public function __construct(
        private AttemptGateway $gateways,
        private ApplyProviderPayment $apply,
        private CaptureAuthorizedPayment $capture,
        private VoidAuthorization $void,
    ) {}

    /**
     * @param  string|null  $providerPaymentId  the payment an event is about: adopted when the
     *                                          attempt never stored its ID (a crash right after
     *                                          creating it), once the gateway confirms it is ours
     * @param  bool  $complete  false: only re-read and apply; the caller completes an authorization itself
     * @param  CallBudget|null  $budget  the caller's time budget: a payer request (the status
     *                                   re-read) or a job (CallBudget::forJob())
     */
    public function handle(string $attemptId, SyncReason $reason, ?string $providerPaymentId = null, bool $complete = true, ?CallBudget $budget = null): PaymentAttempt
    {
        if (Transactions::open()) {
            throw new LogicException('Syncing calls the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $target = $attempt->provider_payment_id ?? $providerPaymentId;

        // A closed attempt is only re-read when the gateway reports on it
        // (webhook): a payment that succeeded after it was closed must not go
        // unnoticed (ApplyProviderPayment flags it for review, ADR-0051).
        $closedButReported = $reason === SyncReason::Webhook && $attempt->status->isTerminal() && $attempt->status !== PaymentAttemptStatus::Succeeded;

        if ($target === null || ($attempt->status->isTerminal() && ! $closedButReported)) {
            return $attempt;
        }

        if ($providerPaymentId !== null && $providerPaymentId !== $target) {
            Log::warning('A payment event names an attempt that holds another payment.', ['payment_attempt_id' => $attempt->id]);

            return $attempt;
        }

        if ($budget !== null && ! $budget->affords()) {
            // No time for the re-read: the next poll, event or reconciliation does it.
            Log::warning('No time left to re-read a payment; left to the next check.', ['payment_attempt_id' => $attempt->id]);

            return $attempt;
        }

        $idleSince = $attempt->updated_at ?? CarbonImmutable::now();
        [$gateway, $connection] = $this->gateways->for($attempt);
        $payment = $this->gateways->guard($connection, static fn () => $gateway->retrievePayment($connection, $target));

        if ($payment->attemptReference !== $attempt->id) {
            Log::warning('A gateway payment does not name the attempt it was read for.', ['payment_attempt_id' => $attempt->id]);

            return $attempt;
        }

        if ($attempt->provider_payment_id === null && ! self::createdForAttempt($payment, $attempt)) {
            Log::warning('A gateway payment was not adopted: it was not created while its attempt could create it.', ['payment_attempt_id' => $attempt->id]);

            return $attempt;
        }

        $attempt = $this->apply->handle($attempt->id, $payment)->attempt;

        // A 3D Secure step the payer abandoned keeps the link reserved:
        // canceled after `abandon_action_after_minutes`, the link is then
        // payable again (or expires) through the usual path.
        if ($reason === SyncReason::Reconciliation && $attempt->status === PaymentAttemptStatus::RequiresAction
            && $idleSince->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes(max(1, config()->integer('axispay.checkout.abandon_action_after_minutes'))))) {
            try {
                return $this->void->handle($attempt->id, VoidReason::AbandonedAction, budget: $budget);
            } catch (CallBudgetExhausted) {
                Log::warning('No time left to cancel an abandoned 3D Secure step; left to the next check.', ['payment_attempt_id' => $attempt->id]);

                return $attempt;
            }
        }

        if ($attempt->status !== PaymentAttemptStatus::RequiresCapture || ! $complete) {
            return $attempt;
        }

        $result = $this->capture->handle($attempt->id, budget: $budget);

        // Not completed now (another actor holds it, a gateway error, or no
        // time left in the payer's request): one more try a minute later.
        if ($result->outcome === CaptureOutcome::Pending && $reason !== SyncReason::Reconciliation) {
            CompleteAuthorizedPaymentJob::dispatch($attempt->id)->delay(now()->addMinute());
        }

        return $result->attempt;
    }

    /**
     * ADR-0051: a payment is adopted by its metadata only when the gateway
     * created it while this attempt's own create call could have: from just
     * before the attempt existed until its idempotency keys expire (24 h).
     * Metadata alone can be written by anyone with access to the account.
     */
    private static function createdForAttempt(ProviderPayment $payment, PaymentAttempt $attempt): bool
    {
        if ($payment->createdAt === null || $attempt->created_at === null) {
            return false;
        }

        $created = CarbonImmutable::createFromTimestampUTC($payment->createdAt);

        return $created->greaterThanOrEqualTo($attempt->created_at->subMinute())
            && $created->lessThanOrEqualTo($attempt->created_at->addDay());
    }
}
