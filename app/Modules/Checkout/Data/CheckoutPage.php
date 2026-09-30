<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Checkout\Enums\CheckoutPhase;
use App\Modules\Checkout\Enums\CheckoutState;
use App\Modules\Legal\Data\LegalDocument;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Everything the payment page renders (plan 11.2, 11.3, docs/frontend/checkout-design.md). Built by
 * CheckoutPageBuilder; views only present it. Never carries the link's
 * metadata, client reference or any secret (plan 11.3).
 */
final readonly class CheckoutPage
{
    /**
     * @param  MerchantLogo|null  $merchantLogo  the merchant's logo; null shows the name (ADR-0056)
     * @param  array<string, LegalDocument>  $legal  the merchant's published legal documents, by kind (ADR-0056)
     * @param  list<array{field: string, required: bool}>  $payerFields
     * @param  array<string, mixed>|null  $client  the page script's configuration (active only)
     */
    public function __construct(
        public CheckoutState $state,
        public string $token,
        public string $merchant,
        public ?MerchantLogo $merchantLogo,
        public ?string $supportEmail,
        public array $legal,
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

    /** The merchant's privacy notice; payer fields are only collected with one (ADR-0051). */
    public function privacyNotice(): ?LegalDocument
    {
        return $this->legal[LegalDocumentKind::Privacy->value] ?? null;
    }
}
