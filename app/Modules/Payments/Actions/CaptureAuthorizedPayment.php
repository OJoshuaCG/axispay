<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
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
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * ADR-0050 steps 4 and 5 for an AUTHORIZED attempt (`requires_capture`):
 *
 *  1. the merchant's decision: the one already kept on the attempt, or ask
 *     the PrePaymentValidator with no lock and no open transaction (rules.md
 *     rule 7b; Phase 4: never configured → approved). The decision is kept at
 *     once, so it is never asked twice;
 *  2. re-lock the link, then the attempt, and re-verify: the attempt must
 *     still be authorized; if the link was closed meanwhile (expired,
 *     canceled, closed tenant) the authorization is voided instead of
 *     captured (if the gateway says it already succeeded, the payment wins);
 *  3. approved → capture (stable idempotency key); rejected → void.
 *
 * Gateway errors never escape: the attempt stays authorized, the answer is
 * Pending, and the webhook or the reconciliation completes it later with the
 * kept decision. The caller passes its lease token when it already holds the
 * lease (the checkout's confirmation); otherwise the lease is taken here and
 * a held lease answers Pending.
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

    public function handle(string $attemptId, ?string $leaseToken = null): CaptureResult
    {
        if (Transactions::open()) {
            throw new LogicException('Completing an authorized payment calls the merchant and the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);

        if ($attempt->status !== PaymentAttemptStatus::RequiresCapture) {
            return new CaptureResult(CaptureOutcome::NotAuthorized, $attempt);
        }

        $token = $leaseToken ?? $this->lease->acquire($attemptId);

        if ($token === null) {
            return new CaptureResult(CaptureOutcome::Pending, $attempt);
        }

        try {
            return $this->complete($attempt, $token);
        } finally {
            if ($leaseToken === null) {
                $this->lease->release($attemptId, $token);
            }
        }
    }

    private function complete(PaymentAttempt $attempt, string $token): CaptureResult
    {
        $outcome = $attempt->validation_outcome;
        $payerMessage = $attempt->validation_payer_message;

        if ($outcome === null) {
            // Step 4, outside any lock or transaction (rule 7b).
            $decision = $this->validator->decide(PaymentLink::query()->findOrFail($attempt->payment_link_id), $attempt);
            [$outcome, $payerMessage] = [$decision->outcome(), $decision->payerMessage];

            PaymentAttempt::query()->whereKey($attempt->id)->whereNull('validation_outcome')->update([
                'validation_outcome' => $outcome->value,
                'validation_payer_message' => $payerMessage !== null ? mb_substr($payerMessage, 0, 500) : null,
            ]);

            // Another actor may have stored its decision first: the stored one wins.
            $stored = PaymentAttempt::query()->findOrFail($attempt->id);
            $outcome = $stored->validation_outcome ?? $outcome;
            $payerMessage = $stored->validation_payer_message;
        }

        // Re-verify after the (possibly slow) merchant call: link first, then attempt.
        [$current, $linkClosed] = DB::transaction(static function () use ($attempt): array {
            $link = PaymentLink::query()->lockForUpdate()->findOrFail($attempt->payment_link_id);
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $tenantClosed = Tenant::query()->find($link->tenant_id)?->status === TenantStatus::Closed;

            return [$locked, $tenantClosed || in_array($link->status, [PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled], true)];
        });

        if ($current->status !== PaymentAttemptStatus::RequiresCapture) {
            return new CaptureResult(CaptureOutcome::NotAuthorized, $current);
        }

        // The lease may have expired during the merchant call: whoever holds
        // it now decides; this actor stops without touching the gateway.
        if (! AttemptLease::holds($current, $token) || ! $this->lease->extend($current->id, $token)) {
            return new CaptureResult(CaptureOutcome::Pending, $current);
        }

        if ($linkClosed || ! $outcome->allowsCapture()) {
            try {
                $voided = $this->void->handle($current->id, $linkClosed ? 'link_closed' : 'merchant_rejected', $token);
            } catch (GatewayException $e) {
                Log::warning('Void outcome unknown; left to the webhook and the reconciliation.', ['payment_attempt_id' => $current->id, 'exception' => $e::class]);

                return new CaptureResult(CaptureOutcome::Pending, $current);
            }

            if ($voided->status === PaymentAttemptStatus::Succeeded) {
                return new CaptureResult(CaptureOutcome::Captured, $voided);
            }

            return new CaptureResult($linkClosed ? CaptureOutcome::NotAuthorized : CaptureOutcome::Rejected, $voided, $payerMessage);
        }

        [$gateway, $connection] = $this->gateways->for($current);

        try {
            try {
                $payment = $gateway->capturePayment($connection, (string) $current->provider_payment_id, IdempotencyKeys::capture($current->id));
            } catch (GatewayRequestException $e) {
                // Refused (e.g. the authorization expired): apply what the gateway says now.
                Log::warning('The gateway refused a capture.', ['payment_attempt_id' => $current->id, 'provider_code' => $e->providerCode]);
                $payment = $gateway->retrievePayment($connection, (string) $current->provider_payment_id);
            }
        } catch (GatewayException $e) {
            // Unknown outcome: the webhook or the reconciliation settles it
            // (the next capture reuses the same idempotency key).
            Log::warning('Capture outcome unknown; left to the webhook and the reconciliation.', ['payment_attempt_id' => $current->id, 'exception' => $e::class]);

            return new CaptureResult(CaptureOutcome::Pending, $current);
        }

        $applied = $this->apply->handle($current->id, $payment, leaseToken: $token);
        $status = $applied->attempt->status;

        return new CaptureResult(
            in_array($status, [PaymentAttemptStatus::Succeeded, PaymentAttemptStatus::Processing], true) ? CaptureOutcome::Captured : CaptureOutcome::NotAuthorized,
            $applied->attempt,
        );
    }
}
