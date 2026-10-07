<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Contracts\PaymentGateway;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Payments\Data\CreateRefundData;
use App\Modules\Payments\Data\RefundCreation;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\Payments\Services\RefundSnapshot;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Money\Money;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Refunds a captured payment, fully or in part (plan 16.1, 10.7, ADR-0066).
 * Two steps, so the gateway is never called inside a transaction (rules.md
 * rule 7b) and two refunds can never exceed the payment:
 *
 *  1. In one transaction, link then attempt locked (rules.md rule 8): the
 *     payment must be `succeeded` (`payment_not_refundable`), the amount must
 *     fit in what is left, counting the refunds pending and done
 *     (`refund_exceeds_available`), and the refund is stored `pending`, audited,
 *     with `refund.created` recorded. A refund with the same idempotency key in
 *     this tenant and mode is returned as it is when the body is the same, and
 *     `422 idempotency_key_reused` when it differs, even after the idempotency
 *     record expired.
 *  2. Outside it, the gateway is asked to refund, on the connection the
 *     payment was made with, under the refund's own idempotency key, and the
 *     answer is applied by ApplyProviderRefund (the refund can come back
 *     `pending`; its final state arrives by event). A gateway that refuses
 *     (or whose credentials no longer work) fails the refund at once, which
 *     frees the money; a gateway that cannot be reached leaves it pending and
 *     the call answers 502, so the integrator retries with the same key: the
 *     retry first looks for a refund the gateway made whose answer was lost
 *     (by our ID in its metadata) and never refunds twice.
 *
 * Platform fees are not reversed (ADR-0012).
 */
final readonly class RefundPayment
{
    public function __construct(
        private AttemptGateway $gateways,
        private ApplyProviderRefund $apply,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {}

    public function handle(CreateRefundData $data, RefundCreation $creation): Refund
    {
        if (Transactions::open()) {
            throw new LogicException('Refunding calls the gateway: never inside a transaction.');
        }

        $existing = $this->existing($creation);
        $refund = $existing ?? $this->reserve($data, $creation);

        return $this->submit($refund, retry: $existing !== null);
    }

    private function existing(RefundCreation $creation): ?Refund
    {
        if ($creation->idempotencyKey === null) {
            return null;
        }

        $refund = Refund::query()->where('idempotency_key', $creation->idempotencyKey)->first();

        if ($refund !== null
            && $creation->requestHash !== null
            && $refund->idempotency_request_hash !== null
            && ! hash_equals($refund->idempotency_request_hash, $creation->requestHash)) {
            throw ApiException::of(ApiErrorCode::IdempotencyKeyReused);
        }

        return $refund;
    }

    private function reserve(CreateRefundData $data, RefundCreation $creation): Refund
    {
        $linkId = AttemptLocks::linkIdOf($data->attemptId) ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such payment.');

        try {
            return DB::transaction(function () use ($data, $creation, $linkId): Refund {
                [$link, $attempt] = AttemptLocks::lockLinkThenAttemptOrFail($linkId, $data->attemptId);

                if ($attempt->status !== PaymentAttemptStatus::Succeeded) {
                    throw ApiException::of(ApiErrorCode::PaymentNotRefundable, 'Only a captured payment can be refunded. Void an authorization that was not captured, instead.');
                }

                $available = $this->available($attempt);
                $amount = $data->amount ?? Money::ofMinor($available, $attempt->currency);

                if ($amount->minorAmount < 1 || $amount->minorAmount > $available) {
                    throw ApiException::of(
                        ApiErrorCode::RefundExceedsAvailable,
                        'The refund amount exceeds the refundable balance: '.Money::ofMinor($available, $attempt->currency)->toDecimalString().' '.$attempt->currency->value.' left to refund.',
                        'amount',
                    );
                }

                $refund = new Refund;
                $refund->forceFill([
                    'payment_attempt_id' => $attempt->id,
                    'amount_minor' => $amount->minorAmount,
                    'currency' => $attempt->currency,
                    'status' => RefundState::Pending,
                    'reason' => $data->reason,
                    'origin' => $creation->origin,
                    'created_by_actor_type' => $creation->actor->type->value,
                    'created_by_actor_id' => $creation->actor->id,
                    'idempotency_key' => $creation->idempotencyKey,
                    'idempotency_request_hash' => $creation->idempotencyKey !== null ? $creation->requestHash : null,
                ])->save();

                $this->audit->record(AuditAction::RefundRequested, $refund, [
                    'payment_attempt_id' => $attempt->id,
                    'amount' => $amount->toDecimalString(),
                    'currency' => $attempt->currency->value,
                    'reason' => $data->reason->value,
                    'via' => $creation->origin->value,
                ], actor: $creation->actor);

                $this->events->record(DomainEventType::RefundCreated, 'refund', $refund->id, [
                    'refund' => RefundSnapshot::of($refund),
                    'payment' => PaymentSnapshot::of($attempt, $link),
                ]);

                return $refund;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same idempotency key won the race.
            return $this->existing($creation) ?? throw $e;
        }
    }

    /** What can still be refunded, counting refunds that are pending: the money they hold is not available. */
    private function available(PaymentAttempt $attempt): int
    {
        $reserved = (int) Refund::query()
            ->where('payment_attempt_id', $attempt->id)
            ->whereIn('status', array_map(
                static fn (RefundState $state): string => $state->value,
                array_filter(RefundState::cases(), static fn (RefundState $state): bool => $state->reservesAmount()),
            ))
            ->sum('amount_minor');

        return max(0, $attempt->amount_minor - $reserved);
    }

    private function submit(Refund $refund, bool $retry): Refund
    {
        // Already sent (a replay) or already final: the integrator gets it as it is.
        if ($refund->provider_refund_id !== null || $refund->status !== RefundState::Pending) {
            return $refund;
        }

        $attempt = PaymentAttempt::query()->findOrFail($refund->payment_attempt_id);
        $providerPaymentId = $attempt->provider_payment_id ?? throw new LogicException('A captured payment has a gateway payment.');
        [$gateway, $connection] = $this->gateways->for($attempt);

        try {
            $provider = ($retry ? $this->findMade($gateway, $connection, $providerPaymentId, $refund) : null)
                ?? $this->create($gateway, $connection, $providerPaymentId, $refund);
        } catch (GatewayUnavailableException $e) {
            Log::warning('The gateway could not be reached to refund; the refund stays pending.', ['refund_id' => $refund->id, 'exception' => $e::class]);

            throw ApiException::of(ApiErrorCode::GatewayError);
        } catch (GatewayRequestException $e) {
            return $this->fail($refund, ApplyProviderRefund::FAILED_REFUSED, $e);
        } catch (GatewayAuthenticationException|GatewayConfigurationException $e) {
            return $this->fail($refund, ApplyProviderRefund::FAILED_ACCESS, $e);
        }

        $this->apply->handle($attempt->id, $provider, $refund->id);

        return Refund::query()->findOrFail($refund->id);
    }

    /**
     * The refund call. Stripe keeps answering a server error (5xx) under the
     * key that got it, so such an error is repeated once under a derived key,
     * after checking the gateway did not make the refund anyway.
     */
    private function create(PaymentGateway $gateway, GatewayConnection $connection, string $providerPaymentId, Refund $refund): ProviderRefund
    {
        $key = IdempotencyKeys::refund($refund->id);
        $call = fn (string $key): ProviderRefund => $this->gateways->guard($connection, fn (): ProviderRefund => $gateway->refund($connection, new RefundRequest(
            providerPaymentId: $providerPaymentId,
            amountMinor: $refund->amount_minor,
            idempotencyKey: $key,
            reason: $refund->reason->forGateway(),
            reference: $refund->id,
        )));

        try {
            return $call($key);
        } catch (GatewayUnavailableException $e) {
            if (($e->httpStatus ?? 0) < 500) {
                throw $e;
            }

            return $this->findMade($gateway, $connection, $providerPaymentId, $refund) ?? $call($key.':r1');
        }
    }

    /** A refund of this payment that carries our refund ID: the call was made and its answer lost. */
    private function findMade(PaymentGateway $gateway, GatewayConnection $connection, string $providerPaymentId, Refund $refund): ?ProviderRefund
    {
        $made = $this->gateways->guard($connection, static fn (): array => $gateway->listRefunds($connection, $providerPaymentId));

        foreach ($made as $candidate) {
            if ($candidate->reference === $refund->id) {
                return $candidate;
            }
        }

        return null;
    }

    private function fail(Refund $refund, string $code, Throwable $cause): Refund
    {
        Log::warning('The gateway did not accept a refund.', ['refund_id' => $refund->id, 'exception' => $cause::class]);

        return $this->apply->markFailed($refund->id, $code) ?? $refund;
    }
}
