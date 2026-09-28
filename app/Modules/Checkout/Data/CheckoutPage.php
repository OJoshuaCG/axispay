<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Checkout\Enums\CheckoutPhase;
use App\Modules\Checkout\Enums\CheckoutState;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Everything the payment page renders (plan 11.2, 11.3, DESIGN.md). Built by
 * CheckoutPageBuilder; views only present it. Never carries the link's
 * metadata, client reference or any secret (plan 11.3).
 */
final readonly class CheckoutPage
{
    /**
     * @param  list<array{field: string, required: bool}>  $payerFields
     * @param  array<string, mixed>|null  $client  the page script's configuration (active only)
     */
    public function __construct(
        public CheckoutState $state,
        public string $token,
        public string $merchant,
        public ?string $supportEmail,
        public ?string $privacyUrl,
        public string $description,
        public Money $money,
        public ?CarbonImmutable $expiresSoonAt,
        public ?CarbonImmutable $paidAt,
        public ?string $returnUrl,
        public bool $paidInThisSession,
        public array $payerFields,
        public ?array $client,
        public bool $sandbox,
        public ?CheckoutPhase $phase,
    ) {}

    /** Plan 11.2: informative pages show the description and date, not the amount. */
    public function showsAmount(): bool
    {
        return in_array($this->state, [CheckoutState::Active, CheckoutState::Processing], true)
            || ($this->state === CheckoutState::Paid && $this->paidInThisSession);
    }

    public function collectsPayerData(): bool
    {
        return $this->payerFields !== [];
    }
}
