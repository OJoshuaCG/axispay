<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Payments\Services\IdempotencyKeys;
use App\Modules\Payments\Services\PaymentAttemptStateMachine;
use App\Modules\Shared\Database\Transactions;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Cancels the gateway payment of an attempt that is not captured (ADR-0050):
 * an authorization is voided (no money moves, no refund), an unconfirmed
 * payment is closed. Used by a merchant rejection or `fail_closed` (Phase 5),
 * the reconciliation (authorizations left uncaptured) and the closing of a
 * link that expired or was canceled (plan 9.1). The result is applied like
 * any gateway state: if the payment had already succeeded, the payment wins.
 *
 * Gateway errors propagate (the caller or its job retries with the same key).
 */
final readonly class VoidAuthorization
{
    public function __construct(
        private AttemptGateway $gateways,
        private ApplyProviderPayment $apply,
        private AuditLogger $audit,
        private PaymentAttemptStateMachine $machine,
    ) {}

    /**
     * @param  string  $reason  merchant_rejected | uncaptured_timeout | link_closed
     */
    public function handle(string $attemptId, string $reason): PaymentAttempt
    {
        if (Transactions::open()) {
            throw new LogicException('Voiding calls the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);

        if ($attempt->status->isTerminal()) {
            return $attempt;
        }

        $wasAuthorized = $attempt->status === PaymentAttemptStatus::RequiresCapture;

        if ($attempt->provider_payment_id === null) {
            return $this->closeLocally($attempt->id); // never reached the gateway
        }

        [$gateway, $connection] = $this->gateways->for($attempt);

        try {
            $payment = $gateway->cancelPayment($connection, $attempt->provider_payment_id, IdempotencyKeys::cancel($attempt->id));
        } catch (GatewayConfigurationException $e) {
            // The connection lost its credentials (a disconnected api_key). A
            // payment that was never authorized cannot be charged any more and
            // is closed locally; an authorization must be voided at the
            // gateway, so that case stays loud.
            if ($wasAuthorized) {
                throw $e;
            }

            return $this->closeLocally($attempt->id);
        }

        $applied = $this->apply->handle($attempt->id, $payment);

        if ($wasAuthorized && $applied->attempt->status->isTerminal() && $applied->attempt->status !== PaymentAttemptStatus::Succeeded) {
            DB::transaction(fn () => $this->audit->record(AuditAction::PaymentAuthorizationVoided, $applied->attempt, [
                'reason' => $reason,
                'payment_link_id' => $applied->attempt->payment_link_id,
                'livemode' => $applied->attempt->livemode,
            ], actor: Actor::system()));
        }

        return $applied->attempt;
    }

    private function closeLocally(string $attemptId): PaymentAttempt
    {
        return DB::transaction(function () use ($attemptId): PaymentAttempt {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if (! $locked->status->isTerminal()) {
                $this->machine->transition($locked, $locked->failure_count > 0 ? PaymentAttemptStatus::Failed : PaymentAttemptStatus::Canceled);
            }

            return $locked;
        });
    }
}
