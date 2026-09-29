<?php

declare(strict_types=1);

namespace App\Modules\Branding\Enums;

/** Why an uploaded image was refused (plan 18); messages in lang/{en,es}/branding.php. */
enum ImageRejection: string
{
    case Empty = 'empty';
    case TooLarge = 'too_large';
    case UnsupportedType = 'unsupported_type';
    case Unreadable = 'unreadable';
    case DimensionsTooLarge = 'dimensions_too_large';
    case DimensionsTooSmall = 'dimensions_too_small';

    public function message(): string
    {
        return __('branding.errors.'.$this->value, ['max_mb' => 1, 'max_px' => 2000, 'min_px' => 32]);
    }
}
