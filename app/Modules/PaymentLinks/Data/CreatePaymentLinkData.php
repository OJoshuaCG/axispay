<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Data;

use App\Modules\Fx\Enums\FxMode;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\Enums\CheckoutLocale;
use Carbon\CarbonImmutable;

/**
 * A syntactically valid request to create a link (plan 10.5), produced by
 * PaymentLinkInputParser. Rules that depend on the tenant, its gateway or
 * the clock are checked by CreatePaymentLink.
 */
final readonly class CreatePaymentLinkData
{
    /**
     * @param  array<array-key, string>|null  $metadata  keys are strings, even numeric-looking ones
     * @param  array<string, PayerFieldRequirement>  $payerFields  per-link overrides only
     * @param  list<LineItem>  $lineItems  the merchant's breakdown of the amount (display only), empty for none
     */
    public function __construct(
        public Money $amount,
        public string $description,
        public ?array $metadata = null,
        public ?string $clientReferenceId = null,
        public ?int $expiresInHours = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?FxMode $fxMode = null,
        public ?ExchangeRate $fxRate = null,
        public array $payerFields = [],
        public ?string $returnUrl = null,
        public ?CheckoutLocale $locale = null,
        public ?bool $prePaymentValidation = null,
        public array $lineItems = [],
        public bool $autoRedirect = false,
    ) {}
}
