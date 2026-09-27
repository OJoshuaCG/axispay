<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Card details the checkout reads before confirming (plan 11.4, 13.2): the
 * issuing country (FX rule, Phase 6), the brand and the last four digits.
 * Never the PAN or the CVC: the platform never receives them.
 */
final readonly class PaymentMethodPreview
{
    public function __construct(
        public ?string $country,
        public ?string $brand,
        public ?string $last4 = null,
    ) {}
}
