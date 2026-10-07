<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Shared\Database\Transactions;
use LogicException;

/**
 * Re-reads refunds from the gateway and applies them (ADR-017: the gateway's
 * current state, never an event payload; plan 14.2 step 6). One refund when
 * the event names it (`refund.*`); every refund of the payment when it only
 * says they changed (`charge.refunded`, which also covers refunds made in the
 * gateway's own dashboard). ApplyProviderRefund does the rest.
 *
 * Gateway errors propagate: the event's job retries.
 */
final readonly class SyncProviderRefunds
{
    public function __construct(
        private AttemptGateway $gateways,
        private ApplyProviderRefund $apply,
    ) {}

    public function handle(string $attemptId, ?string $providerRefundId = null): void
    {
        if (Transactions::open()) {
            throw new LogicException('Syncing calls the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        $providerPaymentId = $attempt->provider_payment_id;

        if ($providerPaymentId === null) {
            return;
        }

        [$gateway, $connection] = $this->gateways->for($attempt);

        /** @var list<ProviderRefund> $refunds */
        $refunds = $providerRefundId !== null
            ? [$this->gateways->guard($connection, static fn (): ProviderRefund => $gateway->retrieveRefund($connection, $providerRefundId))]
            : $this->gateways->guard($connection, static fn (): array => $gateway->listRefunds($connection, $providerPaymentId));

        foreach ($refunds as $refund) {
            $this->apply->handle($attempt->id, $refund);
        }
    }
}
