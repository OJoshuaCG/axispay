<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\CaptureResult;
use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Shared\Database\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * ADR-0050 steps 4 and 5 for an AUTHORIZED attempt (`requires_capture`):
 *
 *  1. ask the merchant (PrePaymentValidator) with no lock and no open
 *     transaction (rules.md rule 7b). Phase 4: never configured → approved;
 *  2. re-lock and re-verify: the attempt must still be authorized (it may
 *     have been voided, captured or expired in the meantime);
 *  3. approved → capture (stable idempotency key); rejected → void the
 *     authorization (VoidAuthorization), nothing is charged.
 *
 * Callers: the checkout right after confirming or after 3D Secure, the
 * webhook handler (a payer who closed the tab after 3D Secure) and the
 * checkout status sync. `$callerHoldsLease` is true when the caller already
 * holds the attempt's lease (the checkout confirmation); otherwise the lease
 * is taken here and a held lease answers Pending.
 */
final readonly class CaptureAuthorizedPayment
{
    public function __construct(
        private PrePaymentValidator $validator,
        private AttemptGateway $gateways,
        private AttemptLease $lease,
        private ApplyProviderPayment $apply,
        private VoidAuthorization $void,
    ) {}

    public function handle(string $attemptId, bool $callerHoldsLease = false): CaptureResult
    {
        if (Transactions::open()) {
            throw new LogicException('Completing an authorized payment calls the merchant and the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);

        if ($attempt->status !== PaymentAttemptStatus::RequiresCapture) {
            return new CaptureResult(CaptureOutcome::NotAuthorized, $attempt);
        }

        if (! $callerHoldsLease && ! $this->lease->acquire($attemptId)) {
            return new CaptureResult(CaptureOutcome::Pending, $attempt);
        }

        try {
            return $this->complete($attempt);
        } finally {
            if (! $callerHoldsLease) {
                $this->lease->release($attemptId);
            }
        }
    }

    private function complete(PaymentAttempt $attempt): CaptureResult
    {
        $link = PaymentLink::query()->findOrFail($attempt->payment_link_id);

        // Step 4, outside any lock or transaction (rule 7b).
        $decision = $this->validator->decide($link, $attempt);

        // Re-verify after the (possibly slow) merchant call.
        $current = DB::transaction(static fn (): PaymentAttempt => PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id));

        if ($current->status !== PaymentAttemptStatus::RequiresCapture) {
            return new CaptureResult(CaptureOutcome::NotAuthorized, $current);
        }

        if (! $decision->approved) {
            $voided = $this->void->handle($current->id, 'merchant_rejected');

            return new CaptureResult(CaptureOutcome::Rejected, $voided, $decision->payerMessage);
        }

        [$gateway, $connection] = $this->gateways->for($current);

        try {
            $payment = $gateway->capturePayment($connection, (string) $current->provider_payment_id, IdempotencyKeys::capture($current->id));
        } catch (GatewayRequestException $e) {
            // Refused (e.g. the authorization expired): apply what the gateway says now.
            Log::warning('The gateway refused a capture.', ['payment_attempt_id' => $current->id, 'provider_code' => $e->providerCode]);
            $payment = $gateway->retrievePayment($connection, (string) $current->provider_payment_id);
        } catch (GatewayException $e) {
            // Unknown outcome: the webhook or the reconciliation settles it
            // (the next capture reuses the same idempotency key).
            Log::warning('Capture outcome unknown; left to the webhook and the reconciliation.', ['payment_attempt_id' => $current->id, 'exception' => $e::class]);

            return new CaptureResult(CaptureOutcome::Pending, $current);
        }

        $applied = $this->apply->handle($current->id, $payment);
        $status = $applied->attempt->status;

        return new CaptureResult(
            in_array($status, [PaymentAttemptStatus::Succeeded, PaymentAttemptStatus::Processing], true) ? CaptureOutcome::Captured : CaptureOutcome::NotAuthorized,
            $applied->attempt,
        );
    }
}
