<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Input of `refund` (plan 16.1). Completed by Phase 7.
 */
final readonly class RefundRequest
{
    public function __construct(
        public string $providerPaymentId,
        public int $amountMinor,
        public string $idempotencyKey,
    ) {}
}
