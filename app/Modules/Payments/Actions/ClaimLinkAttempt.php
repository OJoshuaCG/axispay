<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Data\AttemptClaim;
use App\Modules\Payments\Data\AttemptClaimRequest;
use App\Modules\Payments\Enums\ClaimRefusal;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLease;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Claims a link's attempt for a new confirmation (plan 11.4 step 6,
 * ADR-0051). Under the link's lock, the link's single active attempt
 * (rules.md rule 9) is reused (still waiting for a payment method) or
 * created, its lease taken, the payer's data stored, and the link reserved
 * (`processing`), so it can neither expire nor be canceled while the payment
 * is confirmed (plan 9.1). A reservation left by a confirmation that died
 * (lease expired, nothing under way) is taken over. Anything already under
 * way is refused as in progress, never an error: another session creating
 * the attempt at the same time, or repeated deadlocks, answer the same.
 */
final readonly class ClaimLinkAttempt
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private AttemptLease $lease,
        private PaymentLinkStateMachine $links,
        private StorePayerDetails $payerDetails,
    ) {}

    public function handle(PaymentLink $link, AttemptClaimRequest $request): AttemptClaim|ClaimRefusal
    {
        try {
            return DB::transaction(fn (): AttemptClaim|ClaimRefusal => $this->claim($link, $request), 3);
        } catch (UniqueConstraintViolationException) {
            return ClaimRefusal::InProgress; // another session created the link's active attempt at the same time
        } catch (QueryException $e) {
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            Log::warning('Checkout claim kept deadlocking; answered in progress.', ['payment_link_id' => $link->id]);

            return ClaimRefusal::InProgress;
        }
    }

    private function claim(PaymentLink $link, AttemptClaimRequest $request): AttemptClaim|ClaimRefusal
    {
        $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);
        $reclaiming = $locked->status === PaymentLinkStatus::Processing;

        if (! in_array($locked->status, [PaymentLinkStatus::Active, PaymentLinkStatus::Processing], true) || $locked->isCheckoutBlocked()) {
            return $locked->status === PaymentLinkStatus::Paid ? ClaimRefusal::AlreadyPaid : ClaimRefusal::InProgress;
        }

        // The link lock already serializes this link: read its active attempt
        // without a locking range read (no gap locks shared with other links
        // of the tenant), then lock that row by its key.
        $attemptId = PaymentAttempt::query()->activeForLink($locked->id)->value('id');
        $attempt = is_string($attemptId) ? PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->first() : null;
        $attempt = $attempt !== null && ! $attempt->status->isTerminal() ? $attempt : null;

        if ($reclaiming && ($attempt === null || $attempt->status->isInFlight() || $attempt->leaseHeld())) {
            return ClaimRefusal::InProgress; // a live confirmation holds the reservation
        }

        if ($locked->isPastExpiry()) {
            if ($reclaiming) {
                $this->links->resumeAfterAttempt($locked); // expires it
            }

            return ClaimRefusal::Expired;
        }

        $token = $attempt !== null && ! $attempt->status->isInFlight() ? $this->lease->acquireLocked($attempt) : null;

        if ($attempt !== null && $token === null) {
            return ClaimRefusal::InProgress;
        }

        if ($attempt === null) {
            [$attempt, $token] = $this->newAttempt($locked, $request);
        }

        $this->payerDetails->handle($attempt, $request->payer);

        // Forensics only (card testing): never shown to payers (ADR-0051).
        if ($request->cardFingerprint !== null) {
            $attempt->forceFill(['card_fingerprint' => substr($request->cardFingerprint, 0, PaymentAttempt::CARD_FINGERPRINT_MAX)])->save();
        }

        if (! $reclaiming) {
            $this->links->enterProcessing($locked);
        }

        return new AttemptClaim($attempt, (string) $token);
    }

    /**
     * @return array{0: PaymentAttempt, 1: string|null}
     */
    private function newAttempt(PaymentLink $link, AttemptClaimRequest $request): array
    {
        $connection = $request->connection;
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'payment_link_id' => $link->id,
            'provider' => $connection->provider,
            'provider_account_id' => $connection->provider_account_id,
            'gateway_connection_id' => $connection->id,
            'status' => PaymentAttemptStatus::RequiresPaymentMethod,
            'amount_minor' => $request->amount->minorAmount,
            'currency' => $request->amount->currency,
            'original_amount_minor' => $link->amount_minor,
            'original_currency' => $link->currency,
            'client_ip' => $request->clientIp,
            'user_agent' => $request->userAgent !== null ? mb_substr($request->userAgent, 0, PaymentAttempt::USER_AGENT_MAX) : null,
        ]);
        $token = $this->lease->acquireLocked($attempt); // saves the new row with its lease

        return [$attempt, $token];
    }
}
