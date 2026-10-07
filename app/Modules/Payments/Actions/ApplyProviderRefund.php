<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\RefundOrigin;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\Payments\Services\RefundSnapshot;
use App\Modules\Payments\Services\RefundSummary;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE single place where a refund, as the gateway reports it now, is applied
 * to our records (plan 16.1, 14.3, ADR-0066). The API call that creates the
 * refund, the webhook handler and the reconciliation all come here, so there
 * is no duplicated logic (like ApplyProviderPayment for payments).
 *
 * In one transaction, locking the link first and then the attempt (rules.md
 * rule 8):
 *
 *  1. the refund is found by the gateway's ID, or by our own ID read back from
 *     its metadata (the API call whose answer was lost, or an event that
 *     arrived before the call returned); a refund that is neither is one made
 *     in the gateway's own dashboard: it is imported (`provider_dashboard`)
 *     and `refund.created` is recorded;
 *  2. it moves to the gateway's state, only forwards (RefundState::canMoveTo):
 *     `succeeded` records `refund.succeeded`, `failed` and `canceled` record
 *     `refund.failed`;
 *  3. the payment's `amount_refunded_minor` and its link's `refund_status`
 *     are recomputed from the succeeded refunds.
 *
 * A refund of another payment or in another currency is never adopted.
 * Idempotent: applying the same gateway state twice changes and announces
 * nothing (duplicate and out-of-order events). Platform fees are not
 * reversed (ADR-0012).
 */
final readonly class ApplyProviderRefund
{
    /** The generic codes the integrator sees when a refund does not go through (never the gateway's reason). */
    public const string FAILED = 'refund_failed';

    public const string CANCELED = 'refund_canceled';

    /** The gateway refused the refund (a request error): nothing was refunded. */
    public const string FAILED_REFUSED = 'gateway_refused';

    /** The request never reached the gateway and nobody retried it within a day. */
    public const string NOT_SENT = 'refund_not_sent';

    /** The connection's credentials no longer work, so the gateway could not be asked. */
    public const string FAILED_ACCESS = 'gateway_access_denied';

    public function __construct(private DomainEventRecorder $events) {}

    /**
     * @param  string|null  $ourRefundId  the refund this answer is for, when the caller made the call
     * @return Refund|null the refund as it is now; null when the gateway's refund was not applied
     */
    public function handle(string $attemptId, ProviderRefund $provider, ?string $ourRefundId = null): ?Refund
    {
        $linkId = AttemptLocks::linkIdOf($attemptId);

        return DB::transaction(function () use ($linkId, $attemptId, $provider, $ourRefundId): ?Refund {
            [$link, $attempt] = AttemptLocks::lockLinkThenAttemptOrFail($linkId, $attemptId);

            if ($provider->providerPaymentId !== null && $provider->providerPaymentId !== $attempt->provider_payment_id) {
                Log::warning('A gateway refund names another payment than the attempt it was read for; not applied.', ['payment_attempt_id' => $attempt->id]);

                return null;
            }

            if (strtoupper($provider->currency) !== $attempt->currency->value) {
                // Never adopt a refund in another currency (a bug or a tampered object).
                Log::critical('A gateway refund does not match the currency of its payment; not applied.', ['payment_attempt_id' => $attempt->id]);

                return null;
            }

            $refund = $this->find($attempt, $provider, $ourRefundId);
            $isNew = $refund === null;
            $refund ??= $this->import($attempt, $provider);

            if ($refund->provider_refund_id !== null && $refund->provider_refund_id !== $provider->providerRefundId) {
                Log::warning('A gateway refund does not match the refund it was found by; not applied.', ['payment_attempt_id' => $attempt->id]);

                return null;
            }

            $previous = $refund->status;
            $target = RefundState::from($provider->status->value);
            $moves = ! $isNew && $previous !== $target && $previous->canMoveTo($target);

            $refund->provider_refund_id ??= $provider->providerRefundId;

            if ($moves) {
                $this->move($refund, $target);
            }

            $refund->save();
            $this->recompute($attempt, $link);

            $facts = static fn (): array => ['refund' => RefundSnapshot::of($refund), 'payment' => PaymentSnapshot::of($attempt, $link)];

            if ($isNew) {
                $this->events->record(DomainEventType::RefundCreated, 'refund', $refund->id, $facts());
            }

            if ($isNew || $moves) {
                $this->announce($refund, $facts);
            }

            return $refund;
        });
    }

    /**
     * The gateway refused the refund (or could not be asked): the refund
     * fails and no longer holds the money. Only a refund that was never sent
     * can fail this way; one the gateway already knows is moved by its state.
     */
    public function markFailed(string $refundId, string $code = self::FAILED): ?Refund
    {
        $attemptId = $this->attemptOf($refundId);

        if ($attemptId === null) {
            return null;
        }

        return DB::transaction(function () use ($refundId, $attemptId, $code): ?Refund {
            [$link, $attempt] = AttemptLocks::lockLinkThenAttemptOrFail(AttemptLocks::linkIdOf($attemptId), $attemptId);
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();

            if ($refund === null || $refund->status !== RefundState::Pending || $refund->provider_refund_id !== null) {
                return $refund;
            }

            $this->move($refund, RefundState::Failed, $code);
            $refund->save();

            $this->announce($refund, static fn (): array => ['refund' => RefundSnapshot::of($refund), 'payment' => PaymentSnapshot::of($attempt, $link)]);

            return $refund;
        });
    }

    private function attemptOf(string $refundId): ?string
    {
        $attemptId = Refund::query()->whereKey($refundId)->value('payment_attempt_id');

        return is_string($attemptId) ? $attemptId : null;
    }

    private function find(PaymentAttempt $attempt, ProviderRefund $provider, ?string $ourRefundId): ?Refund
    {
        $byGatewayId = Refund::query()->where('provider_refund_id', $provider->providerRefundId)->lockForUpdate()->first();

        if ($byGatewayId !== null) {
            return $byGatewayId->payment_attempt_id === $attempt->id ? $byGatewayId : null;
        }

        $reference = $ourRefundId ?? $provider->reference;

        if ($reference === null || ! Ulid::isValid($reference)) {
            return null;
        }

        return Refund::query()->whereKey($reference)->where('payment_attempt_id', $attempt->id)->lockForUpdate()->first();
    }

    /** A refund made in the gateway's own dashboard (plan 16.1). Under the attempt's lock. */
    private function import(PaymentAttempt $attempt, ProviderRefund $provider): Refund
    {
        $refund = new Refund;
        $refund->forceFill([
            'payment_attempt_id' => $attempt->id,
            'amount_minor' => $provider->amountMinor,
            'currency' => $attempt->currency,
            'status' => RefundState::Pending,
            'reason' => RefundReason::Other,
            'origin' => RefundOrigin::ProviderDashboard,
            'created_by_actor_type' => ActorType::System->value,
        ]);

        $target = RefundState::from($provider->status->value);

        if ($target !== RefundState::Pending) {
            $this->move($refund, $target);
        }

        return $refund;
    }

    private function move(Refund $refund, RefundState $target, ?string $code = null): void
    {
        $refund->status = $target;
        $refund->succeeded_at = $target === RefundState::Succeeded ? CarbonImmutable::now() : null;
        $refund->failure_reason = match ($target) {
            RefundState::Failed => $code ?? self::FAILED,
            RefundState::Canceled => self::CANCELED,
            default => null,
        };
    }

    /**
     * @param  callable(): array<string, mixed>  $facts
     */
    private function announce(Refund $refund, callable $facts): void
    {
        $type = match ($refund->status) {
            RefundState::Succeeded => DomainEventType::RefundSucceeded,
            RefundState::Failed, RefundState::Canceled => DomainEventType::RefundFailed,
            RefundState::Pending => null,
        };

        if ($type !== null) {
            $this->events->record($type, 'refund', $refund->id, $facts());
        }
    }

    /**
     * `amount_refunded_minor` is the sum of the succeeded refunds and the
     * link's summary follows it, under the caller's locks.
     */
    private function recompute(PaymentAttempt $attempt, PaymentLink $link): void
    {
        $refunded = (int) Refund::query()
            ->where('payment_attempt_id', $attempt->id)
            ->where('status', RefundState::Succeeded->value)
            ->sum('amount_minor');

        $attempt->forceFill(['amount_refunded_minor' => $refunded]);

        if ($attempt->isDirty()) {
            $attempt->save();
        }

        $link->forceFill(['refund_status' => RefundSummary::of($attempt)]);

        if ($link->isDirty()) {
            $link->save();
        }
    }
}
