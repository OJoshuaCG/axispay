<?php

declare(strict_types=1);

namespace App\Modules\Branding\Enums;

/**
 * What the platform brand shows in the panels and the checkout footer
 * (ADR-0053). Without an uploaded logo the name is always shown.
 */
enum BrandDisplayMode: string
{
    case LogoAndName = 'logo_and_name';
    case LogoOnly = 'logo_only';
    case NameOnly = 'name_only';

    public function label(): string
    {
        return __('branding.mode.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
