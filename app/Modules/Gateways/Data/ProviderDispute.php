<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

use App\Modules\Gateways\Enums\ProviderDisputeStatus;

/**
 * A dispute (chargeback or inquiry) as the gateway reports it now (plan 16.2).
 * With direct charges the merchant answers it from the gateway's own
 * dashboard; the platform only records and tells. `evidenceDueBy` and
 * `createdAt` are Unix seconds.
 */
final readonly class ProviderDispute
{
    public function __construct(
        public string $providerDisputeId,
        public ProviderDisputeStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $providerPaymentId = null,
        public ?string $reason = null,
        public ?int $evidenceDueBy = null,
        public ?int $createdAt = null,
    ) {}
}
