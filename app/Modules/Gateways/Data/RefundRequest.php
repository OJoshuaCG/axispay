<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Input of `refund` (plan 16.1). `reason` is the provider-neutral reason the
 * gateway understands (`requested_by_customer`, `duplicate`, `fraudulent`);
 * null when ours is `other`, which no gateway has. `reference` is our refund
 * ID, kept in the gateway's metadata so a refund whose answer was lost can be
 * found again without refunding twice.
 */
final readonly class RefundRequest
{
    public function __construct(
        public string $providerPaymentId,
        public int $amountMinor,
        public string $idempotencyKey,
        public ?string $reason = null,
        public ?string $reference = null,
    ) {}
}
