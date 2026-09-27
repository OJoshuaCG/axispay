<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Checkout\Services\CardTestingGuard;
use App\Modules\Checkout\Services\ChargeAmount;
use App\Modules\Checkout\Services\CheckoutUrls;
use App\Modules\Checkout\Services\EffectivePayerFields;
use App\Modules\Checkout\Services\TurnstileVerifier;
use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\PayerFields\Data\PayerData;
use App\Modules\PayerFields\Exceptions\InvalidPayerDataException;
use App\Modules\PayerFields\Services\PayerFieldsValidator;
use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Actions\ApplyProviderPayment;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\ReleaseLinkAfterAttempt;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PayerDetails;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * "Pay" on the checkout (plan 11.4 with ADR-0050's linear flow):
 *
 *  1. the link can still be paid (active, not past expiry, not blocked);
 *  2. payer fields are valid (plan 19.1; stored encrypted, plan 19.2);
 *  3. card-testing protection: rate limits, then Turnstile when required,
 *     verified on the server (plan 11.7);
 *  4. the card behind the confirmation token is read (country, brand);
 *  5. the amount to charge (ChargeAmount: FX hook, Phase 6);
 *  6. under a lock of the link: THE active attempt of the link is reused or
 *     created (one per link, rules.md rule 9) and its lease taken, so a
 *     second tab or device waits instead of paying twice (plan 26.2 case 1);
 *  7. outside the lock: the gateway payment is created (manual capture) and
 *     confirmed with stable idempotency keys → authorized, 3D Secure asked,
 *     or declined;
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
        private CardTestingGuard $guard,
        private TurnstileVerifier $turnstile,
        private GatewayFactory $gateways,
        private ChargeAmount $amounts,
        private AttemptLease $lease,
        private ApplyProviderPayment $apply,
        private CaptureAuthorizedPayment $capture,
        private ExpirePaymentLink $expire,
        private TenantAccess $access,
        private CheckoutUrls $urls,
        private ReleaseLinkAfterAttempt $release,
        private PaymentLinkStateMachine $links,
        private EffectivePayerFields $effectiveFields,
    ) {}

    /**
     * @throws InvalidPayerDataException
     */
    public function handle(PaymentLink $link, CheckoutPaymentInput $input): CheckoutResult
    {
        if (Transactions::open()) {
            throw new LogicException('The checkout calls the gateway: never inside a transaction.');
        }

        if (($closed = $this->closedOutcome($link)) !== null) {
            return CheckoutResult::of($closed);
        }

        $payer = $this->payerFields->validate($this->effectiveFields->for($link), $input->payer);

        if (preg_match(self::TOKEN_PATTERN, $input->confirmationToken) !== 1) {
            return CheckoutResult::of(CheckoutOutcome::Error);
        }

        if (($minutes = $this->guard->throttle($link, $input->clientIp)) !== null) {
            return new CheckoutResult(CheckoutOutcome::RateLimited, minutes: $minutes);
        }

        $turnstileRequired = $this->guard->turnstileRequired($link, $input->sessionDeclines);

        if ($turnstileRequired && ! $this->turnstile->verify($input->turnstileToken, $input->clientIp)) {
            return new CheckoutResult(CheckoutOutcome::TurnstileRequired, turnstileRequired: true);
        }

        $connection = GatewayConnection::query()->current()->first();

        if ($connection === null || ! $connection->status->canCharge() || ! $connection->charges_enabled) {
            Log::warning('Checkout refused: the tenant cannot charge in this mode.', ['payment_link_id' => $link->id]);

            return CheckoutResult::of(CheckoutOutcome::Unavailable);
        }

        $gateway = $this->gateways->for($connection->provider);

        try {
            $card = $gateway->inspectPaymentMethod($connection, $input->confirmationToken);
        } catch (GatewayException $e) {
            Log::warning('The confirmation token could not be read.', ['payment_link_id' => $link->id, 'exception' => $e::class, 'provider_code' => $e->providerCode]);

            return CheckoutResult::of(CheckoutOutcome::Error);
        }

        $amount = $this->amounts->for($link, $card);
        $claimed = $this->claimAttempt($link, $connection, $amount, $payer, $input);

        if ($claimed instanceof CheckoutOutcome) {
            return CheckoutResult::of($claimed);
        }

        [$attempt, $leaseToken] = $claimed;

        try {
            return $this->confirm($link, $attempt, $leaseToken, $amount, $payer, $input, $turnstileRequired);
        } finally {
            // No payment under way (failure, refusal, never reached the
            // gateway): the reserved link is payable again; the lease is freed.
            $this->release->handle($attempt->id, $leaseToken);
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
            $link->status === PaymentLinkStatus::Processing => CheckoutOutcome::InProgress,
            $link->isCheckoutBlocked() => CheckoutOutcome::Blocked,
            default => null,
        };
    }

    /**
     * Step 6: under the link's lock, the link's single active attempt is
     * reused (still waiting for a payment method) or created, its lease
     * taken, and the link reserved (`processing`), so it can neither expire
     * nor be canceled while the payment is confirmed (plan 9.1, ADR-0051).
     * Anything already under way answers `in_progress`.
     *
     * @return array{0: PaymentAttempt, 1: string}|CheckoutOutcome the attempt and the lease token
     */
    private function claimAttempt(PaymentLink $link, GatewayConnection $connection, Money $amount, PayerData $payer, CheckoutPaymentInput $input): array|CheckoutOutcome
    {
        try {
            return DB::transaction(function () use ($link, $connection, $amount, $payer, $input): array|CheckoutOutcome {
                $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

                if ($locked->status !== PaymentLinkStatus::Active || $locked->isPastExpiry() || $locked->isCheckoutBlocked()) {
                    return $locked->status === PaymentLinkStatus::Paid ? CheckoutOutcome::AlreadyPaid : CheckoutOutcome::InProgress;
                }

                $attempt = PaymentAttempt::query()
                    ->where('payment_link_id', $locked->id)
                    ->whereIn('status', PaymentAttemptStatus::activeValues())
                    ->lockForUpdate()
                    ->first();

                $token = $attempt !== null && ! $attempt->status->isInFlight() ? $this->lease->acquireLocked($attempt) : null;

                if ($attempt !== null && $token === null) {
                    return CheckoutOutcome::InProgress;
                }

                if ($attempt === null) {
                    [$attempt, $token] = $this->newAttempt($locked, $connection, $amount, $input);
                }

                $this->storePayer($attempt, $payer);
                $this->links->enterProcessing($locked);

                return [$attempt, (string) $token];
            });
        } catch (UniqueConstraintViolationException) {
            // Another session created the link's active attempt at the same time (rule 9).
            return CheckoutOutcome::InProgress;
        }
    }

    /**
     * @return array{0: PaymentAttempt, 1: string|null}
     */
    private function newAttempt(PaymentLink $link, GatewayConnection $connection, Money $amount, CheckoutPaymentInput $input): array
    {
        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'payment_link_id' => $link->id,
            'provider' => $connection->provider,
            'provider_account_id' => $connection->provider_account_id,
            'gateway_connection_id' => $connection->id,
            'status' => PaymentAttemptStatus::RequiresPaymentMethod,
            'amount_minor' => $amount->minorAmount,
            'currency' => $amount->currency,
            'original_amount_minor' => $link->amount_minor,
            'original_currency' => $link->currency,
            'client_ip' => $input->clientIp,
            'user_agent' => $input->userAgent !== null ? mb_substr($input->userAgent, 0, 512) : null,
        ]);
        $token = $this->lease->acquireLocked($attempt); // saves the new row with its lease

        return [$attempt, $token];
    }

    private function storePayer(PaymentAttempt $attempt, PayerData $payer): void
    {
        if ($payer->isEmpty()) {
            return;
        }

        $details = PayerDetails::query()->where('payment_attempt_id', $attempt->id)->first() ?? new PayerDetails;
        $details->forceFill([
            'payment_attempt_id' => $attempt->id,
            'data' => $payer->toArray(),
            'purge_after' => now()->addMonths(max(1, config()->integer('axispay.payments.payer_retention_months'))),
        ])->save();
    }

    /**
     * Steps 7 and 8, holding the attempt's lease.
     *
     * The payment is created only with what the link fixes (amount,
     * currency, description, our identifiers), under a key fixed per
     * attempt: a retry after a lost answer repeats the very same request, so
     * the gateway replays it instead of refusing the key. What depends on the
     * payer (the receipt e-mail) travels with the confirmation, whose key
     * includes the confirmation token. An amount that changed (Phase 6
     * conversion) is an update whose key includes the new amount.
     */
    private function confirm(PaymentLink $link, PaymentAttempt $attempt, string $leaseToken, Money $amount, PayerData $payer, CheckoutPaymentInput $input, bool $turnstileRequired): CheckoutResult
    {
        $connection = GatewayConnection::query()->findOrFail($attempt->gateway_connection_id);
        $gateway = $this->gateways->for($attempt->provider);
        $request = static fn (string $key, ?string $providerPaymentId = null): PaymentRequest => new PaymentRequest(
            amountMinor: $amount->minorAmount,
            currency: $amount->currency->value,
            description: $link->description,
            metadata: [
                'axispay_tenant_id' => $link->tenant_id,
                'axispay_link_id' => $link->id,
                'axispay_attempt_id' => $attempt->id,
                'axispay_livemode' => $link->livemode ? 'true' : 'false',
            ],
            idempotencyKey: $key,
            providerPaymentId: $providerPaymentId,
        );

        try {
            if ($attempt->provider_payment_id === null) {
                $attempt = $this->apply->handle($attempt->id, $gateway->createOrUpdatePayment($connection, $request(IdempotencyKeys::create($attempt->id))), leaseToken: $leaseToken)->attempt;
            } elseif ($attempt->amount_minor !== $amount->minorAmount || $attempt->currency !== $amount->currency) {
                $updated = $gateway->createOrUpdatePayment($connection, $request(IdempotencyKeys::update($attempt->id, $amount), $attempt->provider_payment_id));
                PaymentAttempt::query()->whereKey($attempt->id)->update(['amount_minor' => $amount->minorAmount, 'currency' => $amount->currency->value]);
                $attempt = $this->apply->handle($attempt->id, $updated, leaseToken: $leaseToken)->attempt;
            }

            $payment = $gateway->confirmPayment(
                $connection,
                (string) $attempt->provider_payment_id,
                $input->confirmationToken,
                IdempotencyKeys::confirm($attempt->id, $input->confirmationToken),
                $this->urls->complete($link),
                $this->access->settings($link->tenant_id)->sendStripeReceipts ? $payer->email() : null,
            );
        } catch (GatewayException $e) {
            // Unknown or refused: the payer may try again (same keys); the
            // webhook and the reconciliation settle any payment that did go through.
            Log::warning('The checkout could not confirm a payment.', ['payment_attempt_id' => $attempt->id, 'exception' => $e::class, 'provider_code' => $e->providerCode]);

            return new CheckoutResult(CheckoutOutcome::Error, turnstileRequired: $turnstileRequired);
        }

        $applied = $this->apply->handle($attempt->id, $payment, $input->clientIp, $leaseToken);
        $status = $applied->attempt->status;

        if ($status === PaymentAttemptStatus::RequiresPaymentMethod) {
            $declined = $payment->failure !== null;

            return new CheckoutResult(
                $declined ? CheckoutOutcome::Declined : CheckoutOutcome::Error,
                turnstileRequired: $this->guard->turnstileRequired($applied->link, $input->sessionDeclines + ($declined ? 1 : 0)),
                declined: $declined,
            );
        }

        if ($status === PaymentAttemptStatus::RequiresAction) {
            return $payment->needsClientAction()
                ? new CheckoutResult(CheckoutOutcome::RequiresAction, clientSecret: $payment->clientSecret)
                : CheckoutResult::of(CheckoutOutcome::Processing);
        }

        if ($status === PaymentAttemptStatus::RequiresCapture) {
            return CompleteCheckoutAuthorization::complete($this->capture, $attempt->id, $leaseToken);
        }

        return CheckoutResult::of(match ($status) {
            PaymentAttemptStatus::Succeeded => CheckoutOutcome::Paid,
            PaymentAttemptStatus::Processing => CheckoutOutcome::Processing,
            default => CheckoutOutcome::Error,
        });
    }
}
