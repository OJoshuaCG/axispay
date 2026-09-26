<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Card details the checkout needs before confirming (plan 11.4, 13.2).
 * Filled by Phase 4.
 */
final readonly class PaymentMethodPreview
{
    public function __construct(
        public ?string $country,
        public ?string $brand,
    ) {}
}
