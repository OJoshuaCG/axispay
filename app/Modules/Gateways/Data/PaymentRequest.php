<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Input of `createOrUpdatePayment` (plan 12.4). Amounts in minor units
 * (rules.md rule 1). Completed by Phase 4.
 */
final readonly class PaymentRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $description,
        public array $metadata,
        public string $idempotencyKey,
        public ?string $providerPaymentId = null,
        public ?string $receiptEmail = null,
    ) {}
}
