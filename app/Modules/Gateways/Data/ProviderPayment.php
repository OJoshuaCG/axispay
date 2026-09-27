<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use SensitiveParameter;

/**
 * A payment as the gateway reports it right now (plan 12.1).
 *
 *  - `status` is the provider-neutral status;
 *  - `clientSecret` is only set while the payer's browser must act (3D
 *    Secure, Stripe's `handleNextAction`). It is handed to that browser once
 *    and never stored, logged or serialized;
 *  - `attemptReference` is our attempt ID read back from the payment's
 *    metadata (foreign payments have none, plan 14.4);
 *  - `failure` is the last payment error, if the last confirmation failed;
 *  - `createdAt` is when the gateway created the payment (Unix seconds).
 */
final readonly class ProviderPayment
{
    public function __construct(
        public string $providerPaymentId,
        public ProviderPaymentStatus $status,
        public int $amountMinor,
        public string $currency,
        public int $amountCapturableMinor = 0,
        #[SensitiveParameter] public ?string $clientSecret = null,
        public ?PaymentMethodPreview $cardPreview = null,
        public ?ProviderPaymentFailure $failure = null,
        public ?string $attemptReference = null,
        public ?string $captureBefore = null,
        public ?int $createdAt = null,
    ) {}

    public function needsClientAction(): bool
    {
        return $this->status === ProviderPaymentStatus::RequiresAction && $this->clientSecret !== null;
    }

    public function __debugInfo(): array
    {
        return ['providerPaymentId' => $this->providerPaymentId, 'status' => $this->status->value];
    }
}
