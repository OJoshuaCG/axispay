<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * What the checkout frontend needs to initialize the gateway SDK (plan 12.1).
 * For Stripe: the publishable key and, for Connect methods, the connected
 * account (`stripeAccount`); `accountId` is NULL with the api_key method,
 * where the merchant's own publishable key is used.
 */
final readonly class CheckoutClientConfig
{
    public function __construct(
        public string $publishableKey,
        public ?string $accountId,
    ) {}
}
