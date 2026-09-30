<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

/**
 * The merchant's logo on the payment pages (ADR-0056 part B): the versioned,
 * same-origin URLs under the link's own address (so they expose nothing the
 * page does not already), and each image's pixel size for the `width` /
 * `height` attributes. Without a dark variant the light logo is shown on a
 * light plate in dark theme.
 */
final readonly class MerchantLogo
{
    public function __construct(
        public string $url,
        public ?string $darkUrl,
        public int $width,
        public int $height,
        public ?int $darkWidth = null,
        public ?int $darkHeight = null,
    ) {}

    public function hasDarkVariant(): bool
    {
        return $this->darkUrl !== null;
    }
}
