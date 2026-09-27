<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Checkout\Enums\CheckoutOutcome;
use SensitiveParameter;

/**
 * Answer of a payment request. `clientSecret` only with `requires_action`
 * (handed to the browser for 3D Secure, never stored). `payerMessage` is the
 * merchant's message of a rejection (Phase 5), already sanitized.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public CheckoutOutcome $outcome,
        #[SensitiveParameter] public ?string $clientSecret = null,
        public ?int $minutes = null,
        public ?string $payerMessage = null,
        public bool $turnstileRequired = false,
        public bool $declined = false,
    ) {}

    public static function of(CheckoutOutcome $outcome): self
    {
        return new self($outcome);
    }
}
