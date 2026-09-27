<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Input of `createOrUpdatePayment` (plan 12.4). Amounts in minor units
 * (rules.md rule 1). Every payment is card-only (ADR-018) and authorized
 * with a separate capture (ADR-0050). `metadata` carries only our own
 * identifiers (plan 11.4). `providerPaymentId` set means "update this
 * payment" (allowed before it is confirmed). Only values the link fixes
 * belong here; payer-dependent ones go with the confirmation (ADR-0051).
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
    ) {}
}
