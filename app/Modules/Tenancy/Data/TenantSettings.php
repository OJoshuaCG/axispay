<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Fx\Enums\FxMode;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Tenancy\Enums\CheckoutLocale;

/**
 * Typed view of `tenants.settings` (plan 7.3). The JSON is always read through
 * this DTO, never as a loose array. Unknown keys are dropped and every value
 * is clamped to the platform limits in config/axispay.php.
 */
final readonly class TenantSettings
{
    public const int DEFAULT_QUOTE_VALIDITY_MINUTES = 30;

    /**
     * @param  ExchangeRate|null  $fxFixedRate  the tenant's own USD to MXN rate for the `fixed` mode (`fx.fixed_rate`, DECIMAL(18,6)); a link may override it
     * @param  array<string, int>  $maxAmountMinor  currency code => maximum per link in minor units (only lowers the platform cap)
     * @param  array<string, PayerFieldRequirement>  $payerFields  payer field => requirement (plan 19.1)
     */
    public function __construct(
        public int $defaultExpirationHours,
        public int $maxExpirationHours,
        public bool $fxConversionEnabled = false,
        public FxMode $fxDefaultMode = FxMode::BanxicoFix,
        public int $fxMarkupBps = 0,
        public int $fxQuoteValidityMinutes = self::DEFAULT_QUOTE_VALIDITY_MINUTES,
        public ?ExchangeRate $fxFixedRate = null,
        public bool $sendStripeReceipts = false,
        public CheckoutLocale $checkoutLocale = CheckoutLocale::Es,
        public array $maxAmountMinor = [],
        public array $payerFields = [],
    ) {}

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $platformMaxHours = config()->integer('axispay.limits.max_expiration_hours');
        $platformDefaultHours = config()->integer('axispay.limits.default_expiration_hours');
        $platformMaxMarkup = config()->integer('axispay.limits.max_fx_markup_bps');
        $minHours = 1;

        $links = self::section($data, 'links');
        $fx = self::section($data, 'fx');
        $checkout = self::section($data, 'checkout');

        $maxHours = self::clamp(self::int($links, 'max_expiration_hours', $platformMaxHours), $minHours, $platformMaxHours);
        $defaultHours = self::clamp(self::int($links, 'default_expiration_hours', $platformDefaultHours), $minHours, $maxHours);

        return new self(
            defaultExpirationHours: $defaultHours,
            maxExpirationHours: $maxHours,
            fxConversionEnabled: ($fx['conversion_enabled'] ?? false) === true,
            fxDefaultMode: self::fxDefaultMode($fx['default_mode'] ?? null),
            fxMarkupBps: self::clamp(self::int($fx, 'markup_bps', 0), 0, $platformMaxMarkup),
            fxQuoteValidityMinutes: self::clamp(self::int($fx, 'quote_validity_minutes', self::DEFAULT_QUOTE_VALIDITY_MINUTES), 1, 1440),
            fxFixedRate: ExchangeRate::tryOf($fx['fixed_rate'] ?? null),
            sendStripeReceipts: ($checkout['send_stripe_receipts'] ?? false) === true,
            checkoutLocale: (is_string($checkout['locale'] ?? null) ? CheckoutLocale::tryFrom($checkout['locale']) : null) ?? CheckoutLocale::Es,
            maxAmountMinor: self::maxAmounts($links),
            payerFields: self::payerFieldSettings(self::section($data, 'payer_fields')),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            'links' => [
                'default_expiration_hours' => $this->defaultExpirationHours,
                'max_expiration_hours' => $this->maxExpirationHours,
                'max_amount_minor' => $this->maxAmountMinor,
            ],
            'fx' => [
                'conversion_enabled' => $this->fxConversionEnabled,
                'default_mode' => $this->fxDefaultMode->value,
                'markup_bps' => $this->fxMarkupBps,
                'quote_validity_minutes' => $this->fxQuoteValidityMinutes,
                'fixed_rate' => $this->fxFixedRate?->toString(),
            ],
            'checkout' => [
                'send_stripe_receipts' => $this->sendStripeReceipts,
                'locale' => $this->checkoutLocale->value,
            ],
            'payer_fields' => array_map(static fn (PayerFieldRequirement $requirement): string => $requirement->value, $this->payerFields),
        ];
    }

    /**
     * The tenant's own cap for a currency, never above the platform cap
     * (ADR-0048). Null when the tenant did not set one.
     */
    public function maxAmountMinorFor(CurrencyCode $currency): ?int
    {
        return $this->maxAmountMinor[$currency->value] ?? null;
    }

    /**
     * A tenant default is a conversion mode (`none` is not a default).
     * `fixed_rate` is the older spelling of `fixed`.
     */
    private static function fxDefaultMode(mixed $value): FxMode
    {
        $mode = is_string($value) ? FxMode::tryFrom($value === 'fixed_rate' ? FxMode::Fixed->value : $value) : null;

        return $mode === null || $mode === FxMode::None ? FxMode::BanxicoFix : $mode;
    }

    /**
     * Positive integers only, for supported currencies, clamped to the
     * platform maximum of the currency.
     *
     * @param  array<mixed>  $links
     * @return array<string, int>
     */
    private static function maxAmounts(array $links): array
    {
        $given = $links['max_amount_minor'] ?? [];
        $result = [];

        foreach (is_array($given) ? $given : [] as $code => $minor) {
            $currency = CurrencyCode::tryFromInput($code);

            if ($currency !== null && is_int($minor) && $minor > 0) {
                $result[$currency->value] = min($minor, config()->integer("axispay.currencies.{$currency->value}.max_charge_minor"));
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $fields
     * @return array<string, PayerFieldRequirement>
     */
    private static function payerFieldSettings(array $fields): array
    {
        $result = [];

        foreach ($fields as $field => $requirement) {
            $parsed = is_string($requirement) ? PayerFieldRequirement::tryFrom($requirement) : null;

            if (is_string($field) && $parsed !== null) {
                $result[$field] = $parsed;
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
