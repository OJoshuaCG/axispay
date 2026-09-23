<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/**
 * Reads the per-currency configuration (enabled flag and charge limits in minor
 * units) from config/paylink.php.
 */
final readonly class CurrencyLimits
{
    public function __construct(private Repository $config) {}

    public function isEnabled(CurrencyCode $currency): bool
    {
        return (bool) $this->config->get("paylink.currencies.{$currency->value}.enabled", false);
    }

    /**
     * @return list<CurrencyCode>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            CurrencyCode::cases(),
            fn (CurrencyCode $currency): bool => $this->isEnabled($currency),
        ));
    }

    public function minChargeMinor(CurrencyCode $currency): int
    {
        return $this->intSetting($currency, 'min_charge_minor');
    }

    public function maxChargeMinor(CurrencyCode $currency): int
    {
        return $this->intSetting($currency, 'max_charge_minor');
    }

    private function intSetting(CurrencyCode $currency, string $key): int
    {
        $value = $this->config->get("paylink.currencies.{$currency->value}.{$key}");

        if (! is_int($value)) {
            throw new LogicException("Missing integer setting paylink.currencies.{$currency->value}.{$key}.");
        }

        return $value;
    }
}
