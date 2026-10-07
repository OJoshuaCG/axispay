<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Checkout\Services\CheckoutConnection;
use App\Modules\Checkout\Services\CheckoutConversion;
use App\Modules\Checkout\Services\CheckoutRateLimiter;
use App\Modules\Checkout\Services\CheckoutUrls;
use App\Modules\Checkout\Services\EffectivePayerFields;
use App\Modules\Checkout\Services\LinkDeclineCounter;
use App\Modules\Checkout\Services\TurnstileVerifier;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayAccessFailures;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\PayerFields\Data\PayerData;
use App\Modules\PayerFields\Exceptions\InvalidPayerDataException;
use App\Modules\PayerFields\Services\PayerFieldsValidator;
use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\ClaimLinkAttempt;
use App\Modules\Payments\Actions\ConfirmAttemptPayment;
use App\Modules\Payments\Actions\ReleaseLinkAfterAttempt;
use App\Modules\Payments\Data\AttemptClaim;
use App\Modules\Payments\Data\AttemptClaimRequest;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Data\ConfirmationRequest;
use App\Modules\Payments\Enums\ClaimRefusal;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Exceptions\CallBudgetExhausted;
use App\Modules\Payments\Exceptions\PaymentOutcomeUnknownException;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\LinkReservation;
use App\Modules\Shared\Database\ConcurrencyErrors;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * "Pay" on the checkout (plan 11.4 with ADR-0050's linear flow). The
 * checkout orchestrates; the Payments module owns the attempt:
 *
 *  1. the link can still be paid (active, not past expiry, not blocked);
 *  2. payer fields are valid (plan 19.1; stored encrypted, plan 19.2);
 *  3. card-testing protection: rate limits, then Turnstile when required,
 *     verified on the server (plan 11.7);
 *  4. the card behind the confirmation token is read (country, brand);
 *  5. the currency (CheckoutConversion, plan 13.2, 13.4): a Mexican card on a
 *     USD link of a Mexican account is converted to MXN, but only after the
 *     payer confirmed the exact amount; nothing is charged until then;
 *  6. ClaimLinkAttempt: THE active attempt of the link is reused or created
 *     (one per link, rules.md rule 9) under the link's lock and its lease
 *     taken, so a second tab or device waits instead of paying twice (plan
 *     26.2 case 1);
 *  7. the confirmation is counted (it is about to reach the gateway), then
 *     ConfirmAttemptPayment creates and confirms the gateway payment with
 *     stable idempotency keys → authorized, 3D Secure asked, or declined;
 *  8. an authorization is completed at once (CaptureAuthorizedPayment:
 *     merchant validation hook, then capture).
 *
 * Every gateway state goes through ApplyProviderPayment. The database
 * follows the gateway; the webhook stays the source of truth (ADR-017).
 */
final readonly class StartCheckoutPayment
{
    private const string TOKEN_PATTERN = '/^ctoken_[A-Za-z0-9_]{1,250}$/';

    public function __construct(
        private PayerFieldsValidator $payerFields,
        private CheckoutRateLimiter $limiter,
        private LinkDeclineCounter $declines,
        private TurnstileVerifier $turnstile,
        private GatewayFactory $gateways,
        private CheckoutConversion $conversion,
        private AttemptLease $lease,
        private CaptureAuthorizedPayment $capture,
        private ExpirePaymentLink $expire,
        private TenantAccess $access,
        private CheckoutUrls $urls,
        private ReleaseLinkAfterAttempt $release,
        private EffectivePayerFields $effectiveFields,
        private GatewayAccessFailures $failures,
        private CheckoutConnection $connection,
        private ClaimLinkAttempt $claim,
        private ConfirmAttemptPayment $confirmation,
    ) {}

    /**
     * @throws InvalidPayerDataException
     */
    public function handle(PaymentLink $link, CheckoutPaymentInput $input): CheckoutResult
    {
        if (Transactions::open()) {
            throw new LogicException('The checkout calls the gateway: never inside a transaction.');
        }

        // The request answers before the web server gives up (ADR-0051).
        $budget = CallBudget::forPayerRequest();

        if (($closed = $this->closedOutcome($link)) !== null) {
            return CheckoutResult::of($closed);
        }

        $payer = $this->payerFields->validate($this->effectiveFields->for($link), $input->payer);

        if (preg_match(self::TOKEN_PATTERN, $input->confirmationToken) !== 1) {
            return CheckoutResult::of(CheckoutOutcome::Error);
        }

        if (($minutes = $this->limiter->check($link, $input->clientIp)) !== null) {
            return new CheckoutResult(CheckoutOutcome::RateLimited, minutes: $minutes);
        }

        $turnstileRequired = $this->declines->turnstileRequired($link, $input->sessionDeclines);

        if ($turnstileRequired && ! $this->turnstile->verify($input->turnstileToken, $input->clientIp)) {
            return new CheckoutResult(CheckoutOutcome::TurnstileRequired, turnstileRequired: true);
        }

        $result = $this->afterTurnstile($link, $input, $payer, $turnstileRequired, $budget);

        // The verified token is spent (Cloudflare accepts a token once):
        // whatever happened next, the page must ask for a fresh one.
        return $turnstileRequired && ! $result->turnstileRequired ? $result->withTurnstileRequired() : $result;
    }

    private function afterTurnstile(PaymentLink $link, CheckoutPaymentInput $input, PayerData $payer, bool $turnstileRequired, CallBudget $budget): CheckoutResult
    {
        $connection = $this->connection->chargeable();

        if ($connection === null) {
            Log::warning('Checkout refused: the tenant cannot charge in this mode.', ['payment_link_id' => $link->id]);

            return CheckoutResult::of(CheckoutOutcome::Unavailable);
        }

        $card = $budget->affords() ? $this->readCard($link, $connection, $input) : null;

        if ($card === null) {
            return CheckoutResult::of(CheckoutOutcome::Error);
        }

        $plan = $this->conversion->plan($link, $connection, $card, $input);

        if ($plan->answer !== null) {
            return $plan->answer;
        }

        $amount = $plan->amount ?? throw new LogicException('A charge plan without an answer has an amount.');
        $claim = $this->claim->handle($link, new AttemptClaimRequest($connection, $amount, $payer, $input->clientIp, $input->userAgent, $card->fingerprint, $plan->quote));

        if ($claim instanceof ClaimRefusal) {
            return CheckoutResult::of(match ($claim) {
                ClaimRefusal::AlreadyPaid => CheckoutOutcome::AlreadyPaid,
                ClaimRefusal::InProgress => CheckoutOutcome::InProgress,
                ClaimRefusal::Expired => CheckoutOutcome::Expired,
            });
        }

        try {
            return $this->confirm($link, $claim, $amount, $payer, $input, $turnstileRequired, $budget);
        } finally {
            $this->releaseClaim($claim);
        }
    }

    /**
     * No payment under way (failure, refusal, never reached the gateway):
     * the reserved link is payable again and the lease is freed. A lock
     * conflict here never replaces the payer's answer: it is logged, the
     * lease expires by itself and the reconciliation frees a link left in
     * `processing` (ADR-0051).
     */
    private function releaseClaim(AttemptClaim $claim): void
    {
        try {
            $this->release->handle($claim->attempt->id, $claim->leaseToken);
        } catch (QueryException $e) {
            $kind = ConcurrencyErrors::kind($e);

            if ($kind === null) {
                throw $e;
            }

            Log::warning('The link could not be released after a confirmation; left to the lease expiry and the reconciliation.', ['payment_attempt_id' => $claim->attempt->id, 'error' => $kind]);
        }
    }

    /** Step 4; a token the gateway cannot read is counted against this client (plan 11.7). */
    private function readCard(PaymentLink $link, GatewayConnection $connection, CheckoutPaymentInput $input): ?PaymentMethodPreview
    {
        $gateway = $this->gateways->for($connection->provider);

        try {
            return $this->failures->guard($connection, static fn (): PaymentMethodPreview => $gateway->inspectPaymentMethod($connection, $input->confirmationToken));
        } catch (GatewayException $e) {
            Log::warning('The confirmation token could not be read.', ['payment_link_id' => $link->id, 'exception' => $e::class, 'provider_code' => $e->providerCode]);

            match (true) {
                $e instanceof GatewayRequestException => $this->limiter->countUnrecognizedToken($link, $input->clientIp),
                // Unavailable or rate limited: counted too, so retries cannot hammer the gateway.
                $e instanceof GatewayUnavailableException => $this->limiter->countGatewayFailure($link, $input->clientIp),
                default => null,
            };

            return null;
        }
    }

    /** Plan 11.2: what a closed or blocked link answers. */
    private function closedOutcome(PaymentLink $link): ?CheckoutOutcome
    {
        if ($link->status === PaymentLinkStatus::Active && $link->isPastExpiry()) {
            $this->expire->handle($link->id);

            return CheckoutOutcome::Expired;
        }

        return match (true) {
            $link->status === PaymentLinkStatus::Paid => CheckoutOutcome::AlreadyPaid,
            // Plan 21.3: a closed tenant no longer collects (a suspended one does, ADR-013).
            ! $this->access->collects($link->tenant_id) => CheckoutOutcome::Canceled,
            $link->status === PaymentLinkStatus::Expired => CheckoutOutcome::Expired,
            $link->status === PaymentLinkStatus::Canceled => CheckoutOutcome::Canceled,
            // A reservation left by a confirmation that died (lease expired,
            // nothing under way) may be taken over (ADR-0051).
            $link->status === PaymentLinkStatus::Processing && ! LinkReservation::isAbandoned($link) => CheckoutOutcome::InProgress,
            $link->isCheckoutBlocked() => CheckoutOutcome::Blocked,
            default => null,
        };
    }

    /** Steps 7 and 8, holding the attempt's lease. */
    private function confirm(PaymentLink $link, AttemptClaim $claim, Money $amount, PayerData $payer, CheckoutPaymentInput $input, bool $turnstileRequired, CallBudget $budget): CheckoutResult
    {
        $attempt = $claim->attempt;

        if (! $this->lease->extend($attempt->id, $claim->leaseToken)) {
            return CheckoutResult::of(CheckoutOutcome::InProgress);
        }

        // Plan 11.7 rules 1-2: only a confirmation that goes on to the
        // gateway counts, reserved atomically (a parallel burst cannot overshoot).
        if (($minutes = $this->limiter->reserveConfirmation($link, $input->clientIp)) !== null) {
            return new CheckoutResult(CheckoutOutcome::RateLimited, minutes: $minutes);
        }

        try {
            $confirmed = $this->confirmation->handle($link, $attempt, $claim->leaseToken, new ConfirmationRequest(
                confirmationToken: $input->confirmationToken,
                amount: $amount,
                returnUrl: $this->urls->complete($link),
                receiptEmail: $this->access->settings($link->tenant_id)->sendStripeReceipts ? $payer->email() : null,
                clientIp: $input->clientIp,
                budget: $budget,
            ));
        } catch (CallBudgetExhausted $e) {
            // Stopped before the confirmation reached the gateway: nothing was charged.
            Log::warning('The checkout ran out of time before confirming a payment.', ['payment_attempt_id' => $attempt->id]);

            return new CheckoutResult(CheckoutOutcome::Error, turnstileRequired: $turnstileRequired);
        } catch (PaymentOutcomeUnknownException $e) {
            // The confirmation may have gone through: never an error for a
            // charge that may be under way; events and the reconciliation settle it.
            Log::warning('The confirmation answer was lost; the payment is left to events and the reconciliation.', ['payment_attempt_id' => $attempt->id]);

            return CheckoutResult::of(CheckoutOutcome::Processing);
        } catch (GatewayException $e) {
            // Unknown or refused: the payer may try again (same keys); the
            // webhook and the reconciliation settle any payment that did go through.
            Log::warning('The checkout could not confirm a payment.', ['payment_attempt_id' => $attempt->id, 'exception' => $e::class, 'provider_code' => $e->providerCode]);

            return new CheckoutResult(CheckoutOutcome::Error, turnstileRequired: $turnstileRequired);
        }

        if ($confirmed === null) {
            return CheckoutResult::of(CheckoutOutcome::InProgress);
        }

        $payment = $confirmed->payment;
        $status = $confirmed->applied->attempt->status;

        if ($status === PaymentAttemptStatus::RequiresPaymentMethod) {
            $declined = $payment->failure !== null;

            return new CheckoutResult(
                $declined ? CheckoutOutcome::Declined : CheckoutOutcome::Error,
                turnstileRequired: $this->declines->turnstileRequired($confirmed->applied->link, $input->sessionDeclines + ($declined ? 1 : 0)),
                declined: $declined,
            );
        }

        if ($status === PaymentAttemptStatus::RequiresAction) {
            return $payment->needsClientAction()
                ? new CheckoutResult(CheckoutOutcome::RequiresAction, clientSecret: $payment->clientSecret, attemptId: $attempt->id)
                : CheckoutResult::of(CheckoutOutcome::Processing);
        }

        if ($status === PaymentAttemptStatus::RequiresCapture) {
            return CompleteCheckoutAuthorization::complete($this->capture, $attempt->id, $claim->leaseToken, $budget);
        }

        return CheckoutResult::of(match ($status) {
            PaymentAttemptStatus::Succeeded => CheckoutOutcome::Paid,
            PaymentAttemptStatus::Processing => CheckoutOutcome::Processing,
            default => CheckoutOutcome::Error,
        });
    }
}
