<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

/**
 * Typed view of `tenants.settings` (plan 7.3). The JSON is always read through
 * this DTO, never as a loose array. Unknown keys are dropped and every value
 * is clamped to the platform limits in config/paylink.php.
 */
final readonly class TenantSettings
{
    public const int DEFAULT_EXPIRATION_HOURS = 168;

    public const int DEFAULT_QUOTE_VALIDITY_MINUTES = 30;

    public function __construct(
        public int $defaultExpirationHours = self::DEFAULT_EXPIRATION_HOURS,
        public int $maxExpirationHours = 2160,
        public bool $fxConversionEnabled = false,
        public string $fxDefaultMode = 'banxico_fix',
        public int $fxMarkupBps = 0,
        public int $fxQuoteValidityMinutes = self::DEFAULT_QUOTE_VALIDITY_MINUTES,
        public bool $sendStripeReceipts = false,
        public string $checkoutLocale = 'es',
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
        $platformMaxHours = self::configInt('paylink.limits.max_expiration_hours', 2160);
        $platformMaxMarkup = self::configInt('paylink.limits.max_fx_markup_bps', 1000);
        $minHours = 1;

        $links = self::section($data, 'links');
        $fx = self::section($data, 'fx');
        $checkout = self::section($data, 'checkout');

        $maxHours = self::clamp(self::int($links, 'max_expiration_hours', $platformMaxHours), $minHours, $platformMaxHours);
        $defaultHours = self::clamp(self::int($links, 'default_expiration_hours', self::DEFAULT_EXPIRATION_HOURS), $minHours, $maxHours);

        $mode = $fx['default_mode'] ?? 'banxico_fix';
        $locale = $checkout['locale'] ?? 'es';

        return new self(
            defaultExpirationHours: $defaultHours,
            maxExpirationHours: $maxHours,
            fxConversionEnabled: ($fx['conversion_enabled'] ?? false) === true,
            fxDefaultMode: in_array($mode, ['banxico_fix', 'fixed_rate'], true) ? $mode : 'banxico_fix',
            fxMarkupBps: self::clamp(self::int($fx, 'markup_bps', 0), 0, $platformMaxMarkup),
            fxQuoteValidityMinutes: self::clamp(self::int($fx, 'quote_validity_minutes', self::DEFAULT_QUOTE_VALIDITY_MINUTES), 1, 1440),
            sendStripeReceipts: ($checkout['send_stripe_receipts'] ?? false) === true,
            checkoutLocale: in_array($locale, ['es', 'en'], true) ? $locale : 'es',
        );
    }

    /**
     * @return array<string, array<string, bool|int|string>>
     */
    public function toArray(): array
    {
        return [
            'links' => [
                'default_expiration_hours' => $this->defaultExpirationHours,
                'max_expiration_hours' => $this->maxExpirationHours,
            ],
            'fx' => [
                'conversion_enabled' => $this->fxConversionEnabled,
                'default_mode' => $this->fxDefaultMode,
                'markup_bps' => $this->fxMarkupBps,
                'quote_validity_minutes' => $this->fxQuoteValidityMinutes,
            ],
            'checkout' => [
                'send_stripe_receipts' => $this->sendStripeReceipts,
                'locale' => $this->checkoutLocale,
            ],
        ];
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

    private static function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }
}
