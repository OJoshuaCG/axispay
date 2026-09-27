<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Checkout\Enums\CheckoutState;

/**
 * Minimal state polled by the page (plan 11.5): the link's page state and,
 * while processing, the phase (`processing`, `validating`); once paid, the
 * link's return URL (plan 11.5).
 */
final readonly class CheckoutStatus
{
    public function __construct(
        public CheckoutState $state,
        public ?string $phase = null,
        public ?string $returnUrl = null,
    ) {}
}
