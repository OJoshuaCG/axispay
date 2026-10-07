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
use App\Modules\Shared\Database\ConcurrencyErrors;
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
 * the attempt at the same time, or lock conflicts that persist after
 * retries (deadlocks, a row changed under MariaDB's snapshot isolation),
 * answer the same.
 */
final readonly class ClaimLinkAttempt
{
    /** Claims under lock conflicts are retried this many times in all (with jitter). */
    private const int TRIES = 3;

    public function __construct(
        private AttemptLease $lease,
        private PaymentLinkStateMachine $links,
        private StorePayerDetails $payerDetails,
    ) {}

    public function handle(PaymentLink $link, AttemptClaimRequest $request): AttemptClaim|ClaimRefusal
    {
        for ($try = 1; ; $try++) {
            // Read in autocommit, BEFORE the transaction: a plain read inside
            // it would open a read snapshot, and MariaDB then refuses to lock
            // a row changed since (ER_CHECKREAD). Re-checked under the locks.
            $attemptId = PaymentAttempt::query()->activeForLink($link->id)->value('id');

            try {
                return DB::transaction(fn (): AttemptClaim|ClaimRefusal => $this->claim($link, $request, is_string($attemptId) ? $attemptId : null));
            } catch (UniqueConstraintViolationException) {
                return ClaimRefusal::InProgress; // another session created the link's active attempt at the same time
            } catch (QueryException $e) {
                $kind = ConcurrencyErrors::kind($e);

                if ($kind === null) {
                    throw $e;
                }

                if ($try < self::TRIES) {
                    ConcurrencyErrors::jitter();

                    continue;
                }

                Log::warning($kind === ConcurrencyErrors::RECORD_CHANGED
                    ? 'Checkout claim kept meeting an attempt changed by another process; answered in progress.'
                    : 'Checkout claim kept hitting lock conflicts; answered in progress.', ['payment_link_id' => $link->id, 'error' => $kind, 'tries' => $try]);

                return ClaimRefusal::InProgress;
            }
        }
    }

    private function claim(PaymentLink $link, AttemptClaimRequest $request, ?string $attemptId): AttemptClaim|ClaimRefusal
    {
        $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);
        $reclaiming = $locked->status === PaymentLinkStatus::Processing;

        if (! in_array($locked->status, [PaymentLinkStatus::Active, PaymentLinkStatus::Processing], true) || $locked->isCheckoutBlocked()) {
            return $locked->status === PaymentLinkStatus::Paid ? ClaimRefusal::AlreadyPaid : ClaimRefusal::InProgress;
        }

        // The active attempt read before the transaction (never a locking
        // range read: no gap locks shared with other links of the tenant),
        // locked by its key and re-checked: still this link's and not final.
        // Otherwise a new one is created; if another session created it
        // meanwhile, the unique active-attempt key refuses it (in progress).
        $attempt = $attemptId !== null ? PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->first() : null;
        $attempt = $attempt !== null && $attempt->payment_link_id === $locked->id && ! $attempt->status->isTerminal() ? $attempt : null;

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

        $this->recordCharge($attempt, $request);
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
     * A reused attempt takes the quote of THIS confirmation (or none): the
     * payer may retry with another card after a decline, and a Mexican card
     * (converted) followed by a foreign one (charged in the link's currency)
     * must not keep the first card's conversion. The stored amount is NOT
     * touched here: a create whose answer was lost may exist at the gateway
     * under its fixed key, so ConfirmAttemptPayment repeats it unchanged and
     * then updates the gateway payment and the stored amount together.
     */
    private function recordCharge(PaymentAttempt $attempt, AttemptClaimRequest $request): void
    {
        $quoteId = $request->fxQuote?->id;

        if ($attempt->fx_quote_id !== $quoteId) {
            $attempt->forceFill(['fx_quote_id' => $quoteId])->save();
        }
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
            'fx_quote_id' => $request->fxQuote?->id,
            'client_ip' => $request->clientIp,
            'user_agent' => $request->userAgent !== null ? mb_substr($request->userAgent, 0, PaymentAttempt::USER_AGENT_MAX) : null,
        ]);
        $token = $this->lease->acquireLocked($attempt); // saves the new row with its lease

        return [$attempt, $token];
    }
}
