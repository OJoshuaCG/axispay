<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Data\ProviderDispute;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptGateway;
use App\Modules\Shared\Database\Transactions;
use LogicException;

/**
 * Re-reads a dispute from the gateway and applies it (ADR-017, plan 14.2
 * step 6, 16.2). ApplyProviderDispute does the rest. Gateway errors
 * propagate: the event's job retries.
 */
final readonly class SyncProviderDispute
{
    public function __construct(
        private AttemptGateway $gateways,
        private ApplyProviderDispute $apply,
    ) {}

    public function handle(string $attemptId, string $providerDisputeId): void
    {
        if (Transactions::open()) {
            throw new LogicException('Syncing calls the gateway: never inside a transaction.');
        }

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        [$gateway, $connection] = $this->gateways->for($attempt);

        $dispute = $this->gateways->guard($connection, static fn (): ProviderDispute => $gateway->retrieveDispute($connection, $providerDisputeId));

        $this->apply->handle($attempt->id, $dispute);
    }
}
