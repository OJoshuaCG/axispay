<?php

declare(strict_types=1);

namespace App\Modules\Branding\Data;

/**
 * An uploaded image after ImageNormalizer: re-encoded bytes (no metadata),
 * their media type, pixel size and SHA-256.
 */
final readonly class NormalizedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}
}
