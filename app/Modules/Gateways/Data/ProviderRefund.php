<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * A refund as the gateway reports it (plan 16.1). Completed by Phase 7.
 */
final readonly class ProviderRefund
{
    public function __construct(
        public string $providerRefundId,
        public string $status,
        public int $amountMinor,
        public string $currency,
    ) {}
}
