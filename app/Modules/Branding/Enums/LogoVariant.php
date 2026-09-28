<?php

declare(strict_types=1);

namespace App\Modules\Branding\Enums;

/** The platform logo for light backgrounds, and its optional dark-mode variant. */
enum LogoVariant: string
{
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return __('branding.variant.'.$this->value);
    }
}
