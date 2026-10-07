<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Fx\Enums\FxMode;
use App\Modules\Shared\Money\ExchangeRate;

/**
 * What the tenant's "Payment settings" form sends (ADR-0063, ADR-0048):
 * currency conversion (`fx.*`) and the link expiration default and maximum
 * (`links.*`), already parsed. UpdateTenantPaymentSettings validates it
 * against the platform limits.
 */
final readonly class TenantPaymentSettingsData
{
    public function __construct(
        public bool $fxConversionEnabled,
        public FxMode $fxDefaultMode,
        public ?ExchangeRate $fxFixedRate,
        public int $fxMarkupBps,
        public int $fxQuoteValidityMinutes,
        public int $defaultExpirationHours,
        public int $maxExpirationHours,
    ) {}
}
