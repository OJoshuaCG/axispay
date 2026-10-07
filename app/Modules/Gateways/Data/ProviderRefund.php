<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

use App\Modules\Gateways\Enums\ProviderRefundStatus;

/**
 * A refund as the gateway reports it now (plan 16.1).
 *
 *  - `providerPaymentId` is the payment the refund belongs to; the handler
 *    checks it against the attempt it is applied to;
 *  - `failureReason` is the gateway's own reason when the refund failed. It
 *    stays internal: the integrator only sees a generic code;
 *  - `reference` is our refund ID read back from the gateway's metadata (a
 *    refund made in the gateway's dashboard has none);
 *  - `createdAt` is when the gateway created the refund (Unix seconds).
 */
final readonly class ProviderRefund
{
    public function __construct(
        public string $providerRefundId,
        public ProviderRefundStatus $status,
        public int $amountMinor,
        public string $currency,
        public ?string $providerPaymentId = null,
        public ?string $failureReason = null,
        public ?string $reference = null,
        public ?int $createdAt = null,
    ) {}
}
