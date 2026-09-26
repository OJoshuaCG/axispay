<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * A payment as the gateway reports it (plan 12.1). `status` becomes the
 * internal normalized enum in Phase 4; `clientAction` is `none`,
 * `client_secret` (Stripe) or `redirect_url` (redirect gateways).
 */
final readonly class ProviderPayment
{
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public string $clientAction,
        public ?PaymentMethodPreview $cardPreview = null,
        public ?string $failure = null,
    ) {}
}
