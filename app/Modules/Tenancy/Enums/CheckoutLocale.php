<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * Languages of the payment page (plan 7.3 `checkout.locale`, 10.5 `locale`).
 */
enum CheckoutLocale: string
{
    case Es = 'es';
    case En = 'en';

    public function label(): string
    {
        return __('payment_links.locale.'.$this->value);
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }
}
