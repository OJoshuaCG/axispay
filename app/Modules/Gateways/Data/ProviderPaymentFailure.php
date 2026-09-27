<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * The last payment error the gateway reports for a payment (plan 9.2, 12.6).
 * `reference` identifies this decline at the gateway (Stripe: the failed
 * charge), so the same decline seen twice is recorded once. `message` is the
 * gateway's explanation for the merchant, already shortened; payers only ever
 * see a generic text (plan 11.7 rule 7).
 */
final readonly class ProviderPaymentFailure
{
    public function __construct(
        public string $reference,
        public ?string $code,
        public ?string $declineCode,
        public ?string $message,
    ) {}
}
