<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\PayerFields\Data\PayerData;
use App\Modules\Shared\Money\Money;

/**
 * What ClaimLinkAttempt needs to reuse or create a link's attempt: the
 * connection that will charge, the amount, the payer's validated data and
 * the client details kept for fraud analysis.
 */
final readonly class AttemptClaimRequest
{
    public function __construct(
        public GatewayConnection $connection,
        public Money $amount,
        public PayerData $payer,
        public ?string $clientIp,
        public ?string $userAgent,
        public ?string $cardFingerprint,
    ) {}
}
