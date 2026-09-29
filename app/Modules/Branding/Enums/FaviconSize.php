<?php

declare(strict_types=1);

namespace App\Modules\Branding\Enums;

/**
 * The favicon sizes generated from one upload (ADR-0053): the browser tab,
 * the Apple touch icon and the Android / home-screen icon.
 */
enum FaviconSize: int
{
    case Tab = 32;
    case AppleTouch = 180;
    case Android = 192;

    /**
     * @return list<int>
     */
    public static function pixels(): array
    {
        return array_map(static fn (self $size): int => $size->value, self::cases());
    }
}
