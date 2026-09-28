<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Data\ConfirmationRequest;
use App\Modules\Payments\Data\ConfirmedAttempt;
use App\Modules\Payments\Exceptions\CallBudgetExhausted;
use App\Modules\Payments\Exceptions\PaymentOutcomeUnknownException;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Money\Money;
use LogicException;

/**
 * Creates (if needed) and confirms the gateway payment of a claimed attempt
 * (plan 11.4 step 7), holding the attempt's lease, and applies the answer
 * (ApplyProviderPayment).
 *
 * The payment is created only with what the link fixes (amount, currency,
 * description, our identifiers), under a key fixed per attempt: a retry
 * after a lost answer repeats the very same request, so the gateway replays
 * it instead of refusing the key; a stored server error is repeated under a
 * derived key (an intent the failed call may have left is never confirmed,
 * so it cannot charge; ADR-0051). What depends on the payer travels with the
 * confirmation, whose key covers every parameter that can vary. An amount
 * that changed (Phase 6 conversion) is an update whose key names the new
 * amount.
 *
 * Returns null when the lease was lost (another actor decides). Gateway
 * errors before the confirmation propagate (nothing was charged; the payer
 * may try again with the same keys); a confirmation whose answer was lost
 * raises PaymentOutcomeUnknownException (the payment may be under way:
 * events and the reconciliation settle it). With a budget, no call starts
 * that would not fit in it (CallBudgetExhausted, raised before the call).
 */
final readonly class ConfirmAttemptPayment
{
    public function __construct(
        private AttemptGateway $gateways,
        private AttemptLease $lease,
        private ApplyProviderPayment $apply,
    ) {}

    /**
     * @throws GatewayException
     * @throws CallBudgetExhausted
     * @throws PaymentOutcomeUnknownException
     */
    public function handle(PaymentLink $link, PaymentAttempt $attempt, string $leaseToken, ConfirmationRequest $request): ?ConfirmedAttempt
    {
        if (Transactions::open()) {
            throw new LogicException('Confirming calls the gateway: never inside a transaction.');
        }

        [$gateway, $connection] = $this->gateways->for($attempt);
        $gateways = $this->gateways;
        $paymentRequest = static fn (Money $charge, string $key, ?string $providerPaymentId = null): PaymentRequest => new PaymentRequest(
            amountMinor: $charge->minorAmount,
            currency: $charge->currency->value,
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

        $budget = $request->budget ?? CallBudget::unlimited();

        if ($attempt->provider_payment_id === null) {
            if (! $budget->affords()) {
                throw CallBudgetExhausted::before('create the payment');
            }

            // Always the attempt's STORED amount: a retry after a lost answer
            // repeats the very same request under the same key.
            $stored = $attempt->money();
            $created = $gateways->retryingCall(
                $connection,
                IdempotencyKeys::create($attempt->id),
                static fn (string $key): ProviderPayment => $gateway->createOrUpdatePayment($connection, $paymentRequest($stored, $key)),
                static fn (): ?ProviderPayment => null,
                ['payment_attempt_id' => $attempt->id, 'operation' => 'create'],
                $budget,
            );
            $attempt = $this->apply->handle($attempt->id, $created, leaseToken: $leaseToken)->attempt;
        }

        $amount = $request->amount;

        if ($attempt->amount_minor !== $amount->minorAmount || $attempt->currency !== $amount->currency) {
            if (! $budget->affords()) {
                throw CallBudgetExhausted::before('update the payment');
            }

            $current = $attempt;
            $updated = $gateways->guard($connection, static fn (): ProviderPayment => $gateway->createOrUpdatePayment($connection, $paymentRequest($amount, IdempotencyKeys::update($current->id, $amount), $current->provider_payment_id)));
            PaymentAttempt::query()->whereKey($attempt->id)->update(['amount_minor' => $amount->minorAmount, 'currency' => $amount->currency->value]);
            $attempt = $this->apply->handle($attempt->id, $updated, leaseToken: $leaseToken)->attempt;
        }

        if (! $this->lease->extend($attempt->id, $leaseToken)) {
            return null;
        }

        $providerPaymentId = (string) $attempt->provider_payment_id;
        $confirmKey = IdempotencyKeys::confirm($attempt->id, $request->confirmationToken, $request->receiptEmail, $request->returnUrl);
        if (! $budget->affords()) {
            throw CallBudgetExhausted::before('confirm the payment');
        }

        try {
            $payment = $gateways->guard($connection, static fn (): ProviderPayment => $gateway->confirmPayment($connection, $providerPaymentId, $request->confirmationToken, $confirmKey, $request->returnUrl, $request->receiptEmail));
        } catch (GatewayUnavailableException $e) {
            throw PaymentOutcomeUnknownException::after($e);
        }

        return new ConfirmedAttempt($payment, $this->apply->handle($attempt->id, $payment, $request->clientIp, $leaseToken));
    }
}
