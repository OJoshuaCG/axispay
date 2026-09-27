<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Checkout\Enums\CheckoutState;

/**
 * Minimal state polled by the page (plan 11.5): the link's page state and,
 * while processing, the phase (`authorizing`, `validating`, `capturing`).
 */
final readonly class CheckoutStatus
{
    public function __construct(
        public CheckoutState $state,
        public ?string $phase = null,
    ) {}
}
