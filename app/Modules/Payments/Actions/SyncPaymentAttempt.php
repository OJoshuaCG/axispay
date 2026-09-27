<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
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
 *  - reconciliation, authorization older than
 *    `axispay.payments.void_authorized_after_minutes` → voided: authorize and
 *    capture happen seconds apart, so a stale one is a flow that stopped;
 *  - otherwise → CaptureAuthorizedPayment (a payer who closed the tab after
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
     */
    public function handle(string $attemptId, SyncReason $reason, ?string $providerPaymentId = null, bool $complete = true): PaymentAttempt
    {
        if (Transactions::open()) {
            throw new LogicException('Syncing calls the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $target = $attempt->provider_payment_id ?? $providerPaymentId;

        if ($attempt->status->isTerminal() || $target === null) {
            return $attempt;
        }

        if ($providerPaymentId !== null && $providerPaymentId !== $target) {
            Log::warning('A payment event names an attempt that holds another payment.', ['payment_attempt_id' => $attempt->id]);

            return $attempt;
        }

        $idleSince = $attempt->updated_at ?? CarbonImmutable::now();
        [$gateway, $connection] = $this->gateways->for($attempt);
        $payment = $gateway->retrievePayment($connection, $target);

        if ($payment->attemptReference !== $attempt->id) {
            Log::warning('A gateway payment does not name the attempt it was read for.', ['payment_attempt_id' => $attempt->id]);

            return $attempt;
        }

        $attempt = $this->apply->handle($attempt->id, $payment)->attempt;

        // A 3D Secure step the payer abandoned keeps the link reserved:
        // canceled after `abandon_action_after_minutes`, the link is then
        // payable again (or expires) through the usual path.
        if ($reason === SyncReason::Reconciliation && $attempt->status === PaymentAttemptStatus::RequiresAction
            && $idleSince->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes(max(1, config()->integer('axispay.checkout.abandon_action_after_minutes'))))) {
            return $this->void->handle($attempt->id, 'abandoned_action');
        }

        if ($attempt->status !== PaymentAttemptStatus::RequiresCapture || ! $complete) {
            return $attempt;
        }

        if ($reason === SyncReason::Reconciliation && $this->isStale($attempt)) {
            return $this->void->handle($attempt->id, 'uncaptured_timeout');
        }

        $result = $this->capture->handle($attempt->id);

        if ($result->outcome === CaptureOutcome::Pending && $reason === SyncReason::Webhook) {
            CompleteAuthorizedPaymentJob::dispatch($attempt->id)->delay(now()->addMinute());
        }

        return $result->attempt;
    }

    private function isStale(PaymentAttempt $attempt): bool
    {
        $authorizedAt = $attempt->authorized_at ?? $attempt->updated_at ?? CarbonImmutable::now();

        return $authorizedAt->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes(config()->integer('axispay.payments.void_authorized_after_minutes')));
    }
}
